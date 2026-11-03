<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\ReceivableCollectionStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderPlatformReceivable;
use App\Domain\Order\Models\OrderReceivableCollection;
use App\Domain\Order\PartnerCollectionDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opens the record of whether Banij's receivable has been collected, once, when
 * the Partner's own gateway has verified the customer's payment (D36).
 *
 * The deadline is worked out here from the setting as it is now, and frozen on
 * the row: a later change to the setting reaches only the collections opened
 * after it. The clock starts when the payment was verified, not when this runs,
 * so a delayed event does not give the Partner longer than they were told.
 *
 * **Once per order.** The unique index on the order is what makes a repeated or
 * racing verification answer with the record that is already there.
 */
class OpenReceivableCollection
{
    public function __construct(
        protected PartnerCollectionDeadline $deadline,
        protected DatabaseManager $database,
    ) {}

    /**
     * @return OrderReceivableCollection|null null when the payment is not a Partner-owned one with a frozen receivable
     */
    public function handle(Payment $payment): ?OrderReceivableCollection
    {
        if (! $payment->isPartnerOwned() || ! $payment->status->isSettled()) {
            return null;
        }

        $order = Order::query()->where('payment_id', $payment->id)->first();
        $receivable = $order === null ? null : OrderPlatformReceivable::query()->where('order_id', $order->id)->first();

        if ($order === null || $receivable === null) {
            return null;
        }

        $existing = OrderReceivableCollection::query()->where('order_id', $order->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $verifiedAt = $payment->completed_at ?? CarbonImmutable::now();
        $hours = $this->deadline->hours();

        try {
            return $this->database->transaction(fn (): OrderReceivableCollection => OrderReceivableCollection::query()->create([
                'order_platform_receivable_id' => $receivable->id,
                'order_id' => $order->id,
                'business_account_id' => $receivable->business_account_id,
                'payment_id' => $payment->id,
                'currency_code' => $receivable->currency_code,
                'amount' => $receivable->total,
                'status' => ReceivableCollectionStatus::PendingCollection,
                'verified_at' => $verifiedAt,
                'deadline_hours' => $hours,
                'deadline_at' => $this->deadline->dueFor($verifiedAt, $hours),
            ]));
        } catch (UniqueConstraintViolationException) {
            return OrderReceivableCollection::query()->where('order_id', $order->id)->firstOrFail();
        }
    }
}
