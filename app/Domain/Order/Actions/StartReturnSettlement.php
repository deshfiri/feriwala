<?php

namespace App\Domain\Order\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\OrderReturn;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Settle a case that inspection has approved (D35): post what it costs, open
 * what it refunds, and move to the settlement stage.
 *
 * **The first place money moves, and only after inspection approved the goods.**
 * Everything here happens in one transaction under one lock on the case, each
 * piece idempotent on its own key, so a repeat — a double click, a retried
 * request, two staff at once — changes nothing the first call did not.
 *
 *   - **The return charge** — half the original delivery charge, snapshotted
 *     when the case was opened — is posted to whoever bears it. The original
 *     delivery is never charged again; whatever was collected for it stays
 *     collected.
 *   - **The refund** is opened for the goods that actually arrived, plus any
 *     adjustment staff approved here with a reason. It is worked out from the
 *     order's immutable line snapshots, never from a figure a screen sent, and
 *     goes through the existing refund machinery (D17). The customer's side of
 *     it is the Partner's commercial matter; what Banij records is the Partner's
 *     liability.
 *   - **The case moves to settlement**, and on to completed as soon as nothing
 *     is owed back to the customer.
 *
 * A case whose goods were not approved cannot be settled: there is no refund,
 * no charge and no stock effect without a person having seen the goods.
 */
class StartReturnSettlement
{
    public function __construct(
        protected PostReturnCharge $charges,
        protected RefundOrderReturn $refunds,
        protected CompleteReturnCase $completion,
        protected AnnounceReturnStatus $announcements,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  Money|null  $adjustment  an extra amount staff approve to give back (delivery, goodwill) — never negative
     *
     * @throws ReturnRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderReturn $return, ?Money $adjustment = null, ?string $adjustmentNote = null): OrderReturn
    {
        if (! $actor->can(PermissionCatalogue::name(PermissionModule::ReturnCase, PermissionAction::Refund))) {
            throw new AuthorizationException('This account may not settle a case.');
        }

        if ($adjustment !== null && ($adjustment->isNegative() || ($adjustment->isPositive() && blank($adjustmentNote)))) {
            throw ReturnRefused::decisionNeedsAReason();
        }

        $started = false;

        try {
            $settled = $this->database->transaction(function () use ($actor, $return, $adjustment, $adjustmentNote, &$started) {
                return $this->settle($actor, $return, $adjustment, $adjustmentNote, $started);
            });
        } catch (UniqueConstraintViolationException) {
            throw ReturnRefused::refundAlreadyOpen();
        }

        $return->setRawAttributes($settled->getAttributes(), sync: true);

        if ($started) {
            $this->refunds->auditStarted($actor, $settled);

            $this->audit->handle(new AuditEntry(
                action: 'order_return.settlement_started',
                actorId: $actor->id,
                auditableType: OrderReturn::class,
                auditableId: $settled->id,
                after: [
                    'reference' => $settled->reference,
                    'status' => $settled->status->value,
                    'responsible_party' => $settled->responsible_party?->value,
                    'return_charge' => $settled->return_charge?->toDecimal(),
                    'refund' => $settled->refund_amount?->toDecimal(),
                    'adjustment' => $settled->other_adjustments?->toDecimal(),
                ],
                note: $adjustmentNote,
                accountId: $settled->business_account_id,
                module: PermissionModule::ReturnCase->value,
            ));
        }

        return $settled;
    }

    /**
     * @throws ReturnRefused
     */
    protected function settle(User $actor, OrderReturn $return, ?Money $adjustment, ?string $adjustmentNote, bool &$started): OrderReturn
    {
        /** @var OrderReturn $locked */
        $locked = OrderReturn::query()->lockForUpdate()->findOrFail($return->id);
        $locked->load(['items.orderItem', 'order.payment', 'responsibilityChanges']);

        if (! $locked->usesCaseWorkflow()) {
            throw ReturnRefused::wrongWorkflow();
        }

        // Settled by the call that got here first: its answer stands.
        if (in_array($locked->status, [ReturnStatus::SettlementPending, ReturnStatus::Completed], true)) {
            return $locked;
        }

        if ($locked->status !== ReturnStatus::InspectionApproved) {
            throw ReturnRefused::inspectionNotFinished();
        }

        $started = true;

        $this->postCharges($actor, $locked);

        $extra = $adjustment !== null && $adjustment->isPositive() ? $adjustment : null;

        if ($extra !== null) {
            $locked->forceFill(['other_adjustments' => $extra, 'adjustment_note' => $adjustmentNote]);
        }

        // An exchange sends goods instead of money: its goods are replaced, not
        // refunded. Only a replacement that cost less than the goods that came back
        // gives money back, and then exactly the difference.
        if ($locked->isExchange()) {
            $owed = $locked->exchange_refund_due;

            if ($owed !== null && $owed->isPositive()) {
                $this->refunds->openFor($actor, $locked, $started, exact: $owed);
            } else {
                $locked->forceFill(['refund_state' => ReturnRefundState::NotRequired])->save();
            }
        } else {
            $this->refunds->openFor($actor, $locked, $started, $extra);
        }

        $locked->moveTo(
            ReturnStatus::SettlementPending,
            new StatusChange(
                actorId: $actor->id,
                reason: 'Charges posted and the refund opened.',
                publicNote: 'returns.notes.settlement_pending',
            ),
            OrderStatusChangeSource::Staff,
        );

        $this->completion->handle($locked->refresh(), $actor->id, OrderStatusChangeSource::Staff);

        $this->announcements->handle($locked->refresh()->load('items'));

        return $locked;
    }

    /**
     * What the case costs, posted to whoever bears it: once each, under keys of
     * their own.
     */
    protected function postCharges(User $actor, OrderReturn $locked): void
    {
        $revision = max(0, $locked->responsibilityChanges->count() - 1);
        $bearer = $locked->responsible_party ?? throw ReturnRefused::wrongWorkflow();

        // The parcel coming back: half of the original delivery. The original
        // delivery itself is history and is never charged again.
        if ($locked->return_charge !== null) {
            $this->charges->handle(
                case: $locked,
                kind: ReturnChargeKind::ReturnCharge,
                amount: $locked->return_charge,
                bearer: $bearer,
                reason: 'Return charge: half of the original delivery charge, for the parcel coming back.',
                actor: $actor,
                revision: $revision,
            );
        }

        // An exchange also sends one new delivery, charged once, on its own key.
        if ($locked->isExchange() && $locked->exchange_delivery_charge !== null) {
            $this->charges->handle(
                case: $locked,
                kind: ReturnChargeKind::ExchangeDelivery,
                amount: $locked->exchange_delivery_charge,
                bearer: $bearer,
                reason: 'Exchange delivery: the one new delivery the replacement is sent by.',
                actor: $actor,
                revision: $revision,
            );
        }
    }
}
