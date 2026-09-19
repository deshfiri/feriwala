<?php

namespace App\Domain\Order\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\OrderRefused;
use App\Domain\Order\Models\Order;
use App\Models\User;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Platform staff cancelling an order nobody has paid for — ERP wholesale or
 * website (§18.4, §18.5).
 *
 * `order.edit`, asked here as well as at the controller, and a reason every time —
 * taking an order away from a buyer and giving its stock back is the decision
 * people later ask about. The cancellation itself is the one every unpaid order
 * goes through, so the payment, the stock and the timeline end the same way; the
 * reason is kept on the timeline for staff and written to the audit log.
 *
 * Never an order whose money has arrived: that needs a refund, not a cancellation.
 */
class CancelUnpaidOrderByStaff
{
    public const MINIMUM_REASON = 10;

    public function __construct(
        protected CancelUnpaidOrder $cancel,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InvalidArgumentException when the reason is too short
     * @throws OrderRefused when the order is not waiting for payment, or its payment is being confirmed
     * @throws LockTimeout when settlement is holding the payment
     */
    public function handle(User $actor, Order $order, string $reason): Order
    {
        Gate::forUser($actor)->authorize('transition', $order);

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('A reason of at least '.self::MINIMUM_REASON.' characters is required.');
        }

        $this->cancel->handle($order, UnpaidOrderCancellation::ByStaff, $actor, $reason);

        $this->audit->handle(new AuditEntry(
            action: 'order.cancelled_unpaid',
            actorId: $actor->id,
            auditableType: Order::class,
            auditableId: $order->id,
            before: ['reference' => $order->reference, 'status' => OrderStatus::PaymentPending->value],
            after: ['reference' => $order->reference, 'status' => $order->status->value],
            reason: $reason,
            accountId: $order->business_account_id,
            module: 'order',
            isSensitive: true,
        ));

        return $order;
    }
}
