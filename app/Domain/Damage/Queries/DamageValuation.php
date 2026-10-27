<?php

namespace App\Domain\Damage\Queries;

use App\Domain\Damage\Data\DamageValue;
use App\Domain\Damage\Enums\DamageValuationBasis;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Money;

/**
 * The most a damaged quantity of an order line can cost (D35).
 *
 * **`snapshotted suggested selling price × damaged quantity`**, read from the
 * line itself — the price the platform suggested when the order was placed,
 * frozen with the line and refused to every later update. It is never the
 * Partner's own store price, their profit, the product's current price or a
 * suggested price edited afterwards, and all of it is exact decimal arithmetic.
 *
 * A line placed before the suggested price was kept on its own has still frozen
 * what the platform keeps from it, which was that same figure for the whole line;
 * a share of it is shared out unit by unit with {@see Money::allocate()}, so the
 * parts add back to the whole exactly. A line with neither has nothing to value
 * it by, and nothing is invented: its maximum is nothing, and a case on it can
 * only be written off.
 */
class DamageValuation
{
    public function for(OrderItem $item, int $quantity): DamageValue
    {
        $currency = $item->unit_price->currency;
        $suggested = $item->suggested_unit_price;

        if ($suggested !== null && $suggested->isPositive()) {
            return new DamageValue(
                basis: DamageValuationBasis::SuggestedPrice,
                unitPrice: $suggested,
                maximum: $suggested->multipliedBy($quantity),
                snapshot: [
                    'source' => 'order_item.suggested_unit_price',
                    'unit_price' => $suggested->toDecimal(),
                    'damaged_quantity' => $quantity,
                    'line_quantity' => $item->quantity,
                    'currency' => $currency->value,
                ],
            );
        }

        $retained = $item->platform_retained_amount;

        if ($retained !== null && $retained->isPositive() && $item->quantity > 0) {
            $parts = $retained->allocate(array_fill(0, $item->quantity, 1));
            $maximum = Money::zero($currency);

            foreach (array_slice($parts, 0, $quantity) as $part) {
                $maximum = $maximum->plus($part);
            }

            return new DamageValue(
                basis: DamageValuationBasis::PlatformRetained,
                unitPrice: null,
                maximum: $maximum,
                snapshot: [
                    'source' => 'order_item.platform_retained_amount',
                    'line_retained' => $retained->toDecimal(),
                    'damaged_quantity' => $quantity,
                    'line_quantity' => $item->quantity,
                    'currency' => $currency->value,
                ],
            );
        }

        return new DamageValue(
            basis: DamageValuationBasis::None,
            unitPrice: null,
            maximum: Money::zero($currency),
            snapshot: [
                'source' => 'none',
                'damaged_quantity' => $quantity,
                'line_quantity' => $item->quantity,
                'currency' => $currency->value,
            ],
        );
    }
}
