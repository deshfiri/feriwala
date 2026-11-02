<?php

namespace App\Domain\Order\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Log\LogManager;

/**
 * What a verified online payment means for a Non-Conditional Store Order's
 * profit (D36).
 *
 * The collection fact for an online order is **the verified payment itself**, not
 * a cash amount somebody types. Each line that recovers its cost from its
 * proceeds takes its own total out of the payment as its collection fact, by the
 * channel the payment's frozen ownership names: Feriwala's gateway, or the
 * Partner's own. The profit still waits for delivery and is credited exactly once
 * through {@see EvaluateOrderProceedsEligibility}, which a repeat of this changes
 * nothing in.
 *
 * Deliberately narrow:
 *
 *   - a payment with no frozen ownership is from before ownership existed and is
 *     **not** reclassified from today's settings, so it records nothing;
 *   - the settled payment has to be exactly the order's total — a figure that
 *     does not match is logged and left for a person, never recorded;
 *   - a line's share is its own `line_total`, so the delivery charge (Banij's)
 *     and any cod fee never become the Partner's profit.
 *
 * Safe to run any number of times and from several places: the confirmation of
 * the payment runs it, and so does delivery, which repairs a collection fact a
 * failed attempt left unwritten.
 */
class SynchronizeOnlineCollectionWithPayment
{
    public function __construct(
        protected EvaluateOrderProceedsEligibility $eligibility,
        protected LogManager $log,
    ) {}

    public function handle(Order $order, ?Payment $payment): void
    {
        if ($payment === null
            || $order->source !== OrderSource::Website
            || ! $payment->status->isSettled()
            || CollectionChannel::forOwnership($payment->gateway_ownership) === null) {
            return;
        }

        if ($payment->business_account_id !== $order->business_account_id
            || $payment->amount->currency !== $order->total->currency
            || ! $payment->amount->equals($order->total)) {
            $this->log->channel('payment')->warning('A settled payment does not match its order, so no collection fact was recorded', [
                'order' => $order->reference,
                'payment' => $payment->reference,
            ]);

            return;
        }

        $order->items
            ->filter(fn (OrderItem $item) => $item->defersProductCost())
            ->each(fn (OrderItem $item) => $this->eligibility->markPaymentCollected($item, $payment, $item->line_total));
    }
}
