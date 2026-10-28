<?php

namespace App\Domain\Damage\Queries;

use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Queries\ReturnEligibility;

/**
 * What a Partner may still report damaged on one delivered order (D35).
 *
 * The same rules the report action enforces, worked out beforehand so the form
 * offers only what will be accepted: the order was delivered, the reporting window
 * has not closed, and each line has units not already reported. A line supplied by
 * a Supplier is shown with its reason instead of a way to report it, because the
 * stock movement for such an item is not supported yet — the action refuses it
 * too, and the screen never offers a flow that would end in that refusal.
 *
 * The answer decides what to *show*; every rule is enforced again, in the action,
 * when the report is made.
 */
class DamageReportEligibility
{
    public function __construct(protected ReturnEligibility $eligibility) {}

    /**
     * @return array{
     *     eligible: bool,
     *     refusal: string|null,
     *     window_closes_at: string|null,
     *     lines: list<array{id: string, sku: string, name: string, variant: string|null, sold: int, reported: int, reportable: int, supplier_backed: bool}>
     * }
     */
    public function for(Order $order): array
    {
        $order->loadMissing('items');

        $lines = [];

        foreach ($order->items as $item) {
            $reported = $this->reportedOn($item);

            $lines[] = [
                'id' => $item->public_id,
                'sku' => $item->sku,
                'name' => $item->product_name,
                'variant' => $item->variant_label,
                'sold' => $item->quantity,
                'reported' => $reported,
                'reportable' => max(0, $item->quantity - $reported),
                'supplier_backed' => $item->isSupplierBacked(),
            ];
        }

        $refusal = $this->refusalFor($order, $lines);

        return [
            'eligible' => $refusal === null,
            'refusal' => $refusal,
            'window_closes_at' => $this->eligibility->windowClosesAt($order)?->toIso8601String(),
            'lines' => $lines,
        ];
    }

    /**
     * Units of this line already reported damaged and not refused.
     */
    public function reportedOn(OrderItem $item): int
    {
        return (int) OrderDamage::query()
            ->where('order_item_id', $item->id)
            ->where('status', '<>', DamageStatus::Rejected->value)
            ->sum('quantity');
    }

    /**
     * @param  list<array{reportable: int, supplier_backed: bool}>  $lines
     */
    protected function refusalFor(Order $order, array $lines): ?string
    {
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::ReturnRequested], true)) {
            return 'order_not_delivered';
        }

        $closes = $this->eligibility->windowClosesAt($order);

        if ($closes !== null && $closes->isPast()) {
            return 'damage_window_closed';
        }

        $offered = array_filter($lines, fn (array $line) => $line['reportable'] > 0 && ! $line['supplier_backed']);

        if ($offered === []) {
            return array_filter($lines, fn (array $line) => $line['reportable'] > 0) === []
                ? 'nothing_reportable'
                : 'supplier_backed';
        }

        return null;
    }
}
