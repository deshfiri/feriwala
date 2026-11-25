<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Models\PartnerStockMovement;
use App\Domain\Inventory\PartnerStockCostLots;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Order\Models\PartnerStorefrontProfitLine;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * What a Partner earns on each line of a Storefront order, from frozen figures
 * only.
 *
 * **Selling side** — the line's goods after its discount (the order's discount
 * is already shared across the lines, exactly, when the order is priced) and
 * without the tax inside the price. Delivery, delivery tax and any other charge
 * are never part of it.
 *
 * **Cost side**, by where the units came from:
 *
 *   - Banij's central stock: the platform price frozen on the line when it was
 *     placed (`platform_retained_amount`, D34) — never the product's base cost,
 *     wholesale price or anything read today;
 *   - the Partner's own stock: what those exact units cost them, from the cost
 *     lots the sale took them from, first in, first out;
 *   - the Partner's own stock sold before cost lots existed: the one price the
 *     Partner ever paid for that unit before the sale — and only when there was
 *     exactly one; otherwise the cost is unproven;
 *   - a cost staff set deliberately for the line, when they have.
 *
 * Both sides count only the units that were kept: what came back on a return is
 * taken out of each, the newest lots first. A cost that cannot be proven is
 * reported with the reason, never guessed.
 */
class StorefrontProfitCalculator
{
    public function __construct(
        protected PartnerStockCostLots $lots,
    ) {}

    /**
     * @return list<array{item: OrderItem, basis: string, quantity: int, returned: int, selling: Money, cost: Money|null, profit: Money|null, unproven: string|null}>
     */
    public function lines(Order $order): array
    {
        $order->loadMissing(['items.stockReservation']);
        $returned = $this->returnedQuantities($order);
        $lines = [];

        foreach ($order->items as $item) {
            $back = min($item->quantity, $returned[$item->id] ?? 0);
            $kept = $item->quantity - $back;

            $selling = $this->lots->share($this->netSelling($item), $item->quantity, 0, $kept);
            [$basis, $cost, $unproven] = $this->cost($order, $item, $back, $kept);

            $lines[] = [
                'item' => $item,
                'basis' => $basis,
                'quantity' => $item->quantity,
                'returned' => $back,
                'selling' => $selling,
                'cost' => $cost,
                'profit' => $cost === null ? null : $selling->minus($cost),
                'unproven' => $cost === null ? ($unproven ?? 'cost_unproven') : null,
            ];
        }

        return $lines;
    }

    /**
     * The line's goods as sold: after discount, without the tax in the price.
     */
    public function netSelling(OrderItem $item): Money
    {
        return $item->line_subtotal->minus($item->discount)->minus($item->tax_included);
    }

    /**
     * @return array{0: string, 1: Money|null, 2: string|null} basis, cost of the kept units, why unproven
     */
    protected function cost(Order $order, OrderItem $item, int $back, int $kept): array
    {
        $correction = DB::table('partner_storefront_profit_corrections')
            ->where('order_item_id', $item->id)
            ->orderByDesc('id')
            ->first(['cost_amount', 'currency_code']);

        if ($correction !== null) {
            $whole = Money::fromDecimal((string) $correction->cost_amount, Currency::from((string) $correction->currency_code));

            return [PartnerStorefrontProfitLine::BASIS_FINANCE_CORRECTION, $this->lots->share($whole, $item->quantity, 0, $kept), null];
        }

        if ($item->stockReservation?->isPartnerSourced() !== true) {
            if ($item->platform_retained_amount === null) {
                return [PartnerStorefrontProfitLine::BASIS_PLATFORM_PRICE, null, 'no_platform_price'];
            }

            return [PartnerStorefrontProfitLine::BASIS_PLATFORM_PRICE, $this->lots->share($item->platform_retained_amount, $item->quantity, 0, $kept), null];
        }

        $fromLots = $this->lots->keptCost($item, $back);

        if ($fromLots !== null) {
            return [PartnerStorefrontProfitLine::BASIS_FIFO_LOTS, $fromLots, null];
        }

        if ($this->lots->allocationsFor((int) $item->stock_reservation_id)->isNotEmpty()) {
            return [PartnerStorefrontProfitLine::BASIS_FIFO_LOTS, null, 'lot_cost_unknown'];
        }

        return $this->historicalSingleCost($order, $item, $kept);
    }

    /**
     * A sale from before cost lots: its cost is proven only when every purchase
     * of that unit the Partner made before the sale was at one and the same net
     * unit price.
     *
     * @return array{0: string, 1: Money|null, 2: string|null}
     */
    public function historicalSingleCost(Order $order, OrderItem $item, int $kept): array
    {
        $basis = PartnerStorefrontProfitLine::BASIS_HISTORICAL_SINGLE_COST;
        $stockItemId = $item->stockReservation?->partner_stock_item_id;

        if ($stockItemId === null) {
            return [$basis, null, 'no_purchase_record'];
        }

        $purchaseLines = PartnerStockMovement::query()
            ->where('partner_stock_item_id', $stockItemId)
            ->where('type', PartnerStockMovementType::PurchaseReceived->value)
            ->where('source_type', 'order_item')
            ->where('occurred_at', '<=', $order->placed_at ?? $order->created_at)
            ->pluck('source_id');

        $purchases = OrderItem::query()->whereIn('id', $purchaseLines)->get();

        if ($purchases->isEmpty()) {
            return [$basis, null, 'no_purchase_record'];
        }

        /** @var OrderItem $reference */
        $reference = $purchases->first();
        $referenceNet = $this->lots->netCostOf($reference);

        foreach ($purchases as $purchase) {
            // Same net unit price exactly: a × qb = b × qa, with no division.
            $same = bccomp(
                bcmul($this->lots->netCostOf($purchase)->toDecimal(), (string) $reference->quantity, 2),
                bcmul($referenceNet->toDecimal(), (string) $purchase->quantity, 2),
                2,
            ) === 0;

            if (! $same) {
                return [$basis, null, 'ambiguous_purchase_cost'];
            }
        }

        return [$basis, $this->lots->share($referenceNet, $reference->quantity, 0, $kept), null];
    }

    /**
     * Units that actually came back, by order line: received on a return that
     * was not rejected or cancelled.
     *
     * @return array<int, int>
     */
    protected function returnedQuantities(Order $order): array
    {
        return OrderReturnItem::query()
            ->whereIn('order_item_id', $order->items->pluck('id'))
            ->whereHas('orderReturn', fn ($query) => $query->whereNotIn('status', [
                ReturnStatus::Rejected->value,
                ReturnStatus::Cancelled->value,
                ReturnStatus::InspectionRejected->value,
            ]))
            ->get(['order_item_id', 'received_quantity'])
            ->groupBy('order_item_id')
            ->map(fn ($rows) => (int) $rows->sum('received_quantity'))
            ->all();
    }
}
