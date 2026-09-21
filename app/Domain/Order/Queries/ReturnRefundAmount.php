<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * How much money a return gives back, per line and in total (§26.3, P6-12).
 *
 * **Goods only, for what actually arrived.** Each line's share of what the
 * customer paid for it — its line total, which already carries its discount
 * and tax — for the units received, never for units asked about or approved
 * but not sent. Delivery is not refunded here: it was a service that was
 * performed, and giving it back is a decision a person takes on the refund
 * itself, not something a return implies.
 *
 * **Never loses a poisha, never invents one.** Integer minor units throughout.
 * A line of three sold for 1000 does not refund 333 per unit and strand the
 * last poisha: the refund for this return is what the units returned *so far*
 * are worth, minus what earlier returns of the line already refunded — so
 * however a line comes back, in one return or several, the total refunded
 * for the whole line is exactly its line total.
 */
class ReturnRefundAmount
{
    /**
     * @return array{total: Money, lines: array<int, Money>} lines keyed by returned-line id
     */
    public function for(OrderReturn $return): array
    {
        $return->loadMissing('items.orderItem');

        $currency = Currency::from($return->order->currency_code);
        $total = Money::zero($currency);
        $lines = [];

        foreach ($return->items as $item) {
            $amount = $this->forLine($item);
            $lines[$item->id] = $amount;
            $total = $total->plus($amount);
        }

        return ['total' => $total, 'lines' => $lines];
    }

    public function forLine(OrderReturnItem $item): Money
    {
        $orderItem = $item->orderItem;
        $currency = Currency::from($orderItem->currency_code);

        if ($item->received_quantity <= 0 || $orderItem->quantity <= 0) {
            return Money::zero($currency);
        }

        $before = $this->alreadyRefundedUnits($item);

        return Money::of(
            $this->worth($orderItem, $before + $item->received_quantity) - $this->worth($orderItem, $before),
            $currency,
        );
    }

    /**
     * What `$units` of this line are worth, rounded down to the poisha.
     *
     * Rounded the same way every time, so the difference of two of these is
     * exact: the whole line is worth exactly its total.
     */
    protected function worth(OrderItem $line, int $units): int
    {
        $units = min($units, $line->quantity);

        return intdiv($line->line_total_minor->minorUnits * $units, $line->quantity);
    }

    /**
     * Units of this line another return has already refunded, or is refunding.
     *
     * Asked at the moment this return's refund is worked out, and the answer is
     * written onto the return then: whichever return is refunded first takes
     * the rounding, and the line's total comes out exact either way.
     */
    protected function alreadyRefundedUnits(OrderReturnItem $item): int
    {
        return (int) OrderReturnItem::query()
            ->where('order_item_id', $item->order_item_id)
            ->whereKeyNot($item->id)
            ->whereHas('orderReturn', fn ($query) => $query->whereIn('refund_state', [
                ReturnRefundState::Pending->value,
                ReturnRefundState::Completed->value,
                ReturnRefundState::ManualReview->value,
            ]))
            ->sum('received_quantity');
    }
}
