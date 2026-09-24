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
 * **Never loses a poisha, never invents one.** Exact decimal Taka throughout
 * (D26), via bcmath. A line of three sold for 1000.00 does not refund 333.33
 * per unit and strand the last poisha: the refund for this return is what the
 * units returned *so far* are worth, minus what earlier returns of the line
 * already refunded — so however a line comes back, in one return or several,
 * the total refunded for the whole line is exactly its line total.
 */
class ReturnRefundAmount
{
    /**
     * Working precision for the per-unit share ratio, well past the
     * currency's own scale, so truncating to it can only ever discard digits
     * that were genuinely insignificant.
     */
    private const RATIO_GUARD_SCALE = 20;

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

        return $this->worth($orderItem, $before + $item->received_quantity)
            ->minus($this->worth($orderItem, $before));
    }

    /**
     * What `$units` of this line are worth, floored to the currency's scale.
     *
     * Rounded the same way every time, so the difference of two of these is
     * exact: the whole line is worth exactly its total. Computed as
     * `line_total × units ÷ quantity` at guard precision, then truncated to
     * scale — bcmath's own truncation is exactly a floor for a non-negative
     * value.
     */
    protected function worth(OrderItem $line, int $units): Money
    {
        $units = min($units, $line->quantity);
        $currency = $line->line_total->currency;
        $scale = $currency->scale();

        $product = bcmul($line->line_total->toDecimal(), (string) $units, self::RATIO_GUARD_SCALE);
        $exact = bcdiv($product, (string) $line->quantity, self::RATIO_GUARD_SCALE);
        $floored = bcadd($exact, '0', $scale);

        return Money::fromDecimal($floored, $currency);
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
