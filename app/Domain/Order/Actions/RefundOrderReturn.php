<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\Refundability;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\Queries\ReturnRefundAmount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Start the money going back for goods that have come back (§26.3, §28, D17,
 * P6-12).
 *
 * **Never moves money itself, and never touches a wallet or the ledger.** A
 * return's refund is a refund like any other: this opens the request, for the
 * goods that actually arrived and nothing more, and the existing refund
 * machinery takes it from there — an administrator decides it (D17: every
 * refund is a decision), then it is sent to the gateway that took the money,
 * claimed before it is sent, idempotent on its own key, reversed in the ledger
 * only once the provider confirms. What happens to that request comes back to
 * the return through {@see SyncReturnRefund}.
 *
 * **Cash on delivery is never refunded to a gateway.** The money was handed to
 * whoever delivered the parcel; it never came through a gateway and cannot go
 * back through one. Where it should go instead — cash, a wallet credit, a
 * mobile transfer — is not something the specification decides, and inventing
 * it would be deciding where a customer's money goes. So such a refund is
 * marked for **manual review**, with its amount worked out and recorded, and a
 * person settles it and says so ({@see SettleReturnRefundManually}). The same
 * applies to an online payment that never actually settled.
 *
 * Asked twice, it answers once: a return whose refund is already under way is
 * returned as it stands.
 */
class RefundOrderReturn
{
    public function __construct(
        protected ReturnRefundAmount $amounts,
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderReturn $return): OrderReturn
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::Order, PermissionAction::Approve))) {
            throw new AuthorizationException('This account may not start a refund for a return.');
        }

        $started = false;

        try {
            // A full closure, not an arrow one: `$started` has to come back out.
            $refunded = $this->database->transaction(function () use ($actor, $return, &$started) {
                return $this->start($actor, $return, $started);
            });
        } catch (UniqueConstraintViolationException) {
            // The open-request index on the payment: another refund of these
            // goods is already waiting for its decision.
            throw ReturnRefused::refundAlreadyOpen();
        }

        $return->setRawAttributes($refunded->getAttributes(), sync: true);

        // Only the call that started it says so; a repeat changed nothing.
        if (! $started) {
            return $refunded;
        }

        $this->audit->handle(new AuditEntry(
            action: 'order_return.refund_started',
            actorId: $actor->id,
            auditableType: OrderReturn::class,
            auditableId: $refunded->id,
            after: [
                'reference' => $refunded->reference,
                'refund_state' => $refunded->refund_state->value,
                'amount' => $refunded->refund_amount?->toDecimal(),
                'currency' => $refunded->currency_code,
                'refund_request' => $refunded->refundRequest?->public_id,
            ],
            accountId: $refunded->business_account_id,
            module: PermissionModule::Order->value,
        ));

        return $refunded;
    }

    /**
     * @param  bool  $started  set when this call is the one that started the refund
     *
     * @throws ReturnRefused
     */
    protected function start(User $actor, OrderReturn $return, bool &$started): OrderReturn
    {
        /** @var OrderReturn $locked */
        $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
        $locked->load(['items.orderItem', 'order.payment']);

        // Already under way, or already settled: the first call's answer stands.
        if (in_array($locked->refund_state, [ReturnRefundState::Pending, ReturnRefundState::Completed, ReturnRefundState::ManualReview], true)) {
            return $locked;
        }

        if ($locked->status !== ReturnStatus::Received) {
            throw ReturnRefused::notInThatState();
        }

        $started = true;

        $amounts = $this->amounts->for($locked);

        foreach ($locked->items as $item) {
            $line = $amounts['lines'][$item->id];
            $item->forceFill([
                'refund_amount' => $line,
                'currency_code' => $line->currency->value,
            ])->save();
        }

        $total = $amounts['total'];

        $locked->forceFill([
            'refund_amount' => $total,
            'currency_code' => $total->currency->value,
        ]);

        $payment = $locked->order->payment;

        if ($locked->isCashOnDelivery() || $payment === null || ! $this->settled($payment)) {
            // Kept as a translation key, so staff read why in their own language.
            $locked->forceFill([
                'refund_state' => ReturnRefundState::ManualReview,
                'refund_note' => $locked->isCashOnDelivery()
                    ? 'returns.refund_notes.cash_on_delivery'
                    : 'returns.refund_notes.never_settled',
            ])->save();
        } elseif ($total->isZero()) {
            // Nothing was paid for these goods, so nothing goes back.
            $locked->forceFill([
                'refund_state' => ReturnRefundState::Completed,
                'refunded_at' => CarbonImmutable::now(),
            ])->save();
        } else {
            $refund = $this->openRefund($actor, $locked, $payment);

            $locked->forceFill([
                'refund_state' => ReturnRefundState::Pending,
                'refund_request_id' => $refund->id,
                'refund_note' => null,
            ])->save();
        }

        $this->announcements->handle($locked->refresh()->load('items'));

        return $locked;
    }

    /**
     * The refund request, for the goods, waiting for its decision (D17).
     *
     * Opened directly rather than through the charge-refund policy, because
     * that policy answers whether a *fee* may ever be refunded. Whether these
     * goods are owed money back was answered when the return was approved and
     * the goods were counted in; what is left is the money decision, which
     * stays an administrator's.
     */
    protected function openRefund(User $actor, OrderReturn $return, Payment $payment): RefundRequest
    {
        /** @var RefundRequest $refund */
        $refund = RefundRequest::create([
            'payment_id' => $payment->id,
            'business_account_id' => $payment->business_account_id,
            'allocation_type' => $payment->purpose === PaymentPurpose::WholesaleOrder
                ? AllocationType::WholesaleGoods
                : AllocationType::WebsiteGoods,
            // RefundRequest::amount is Billing domain (D26); this call site is
            // written against its post-migration column name.
            'amount' => $return->refund_amount,
            'currency_code' => $return->currency_code,
            'refundability' => Refundability::Always,
            'status' => RefundStatus::Requested,
            'reason' => sprintf(
                'Goods returned on %s (%s): %s.',
                $return->reference,
                $return->order->reference,
                $return->items->map(fn (OrderReturnItem $item) => $item->received_quantity.' × '.$item->orderItem->sku)->implode(', '),
            ),
            'requested_by' => $actor->id,
        ]);

        return $refund;
    }

    protected function settled(Payment $payment): bool
    {
        return $payment->status->isSettled() || $payment->status === PaymentStatus::PartiallyRefunded;
    }
}
