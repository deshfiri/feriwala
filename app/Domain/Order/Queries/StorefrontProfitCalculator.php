<?php

namespace App\Domain\Order\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\WholesalePriceResolver;
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
 * What a Partner earns on a Storefront order (D40, amended 2026-11-28):
 *
 *     Partner profit = net goods selling − Partner base cost + delivery charge
 *
 * The delivery charge is added only when the order has one and at least one
 * unit was kept.
 *
 * **Selling side** — the line's goods after its discount (the order's discount
 * is already shared across the lines, exactly, when the order is priced) and
 * without the tax inside the price.
 *
 * **Partner base cost** is the product's **wholesale price** — what a business
 * account pays per unit — never Banij's own base cost and never the suggested
 * selling price. By where the units came from:
 *
 *   - Banij's central stock: the wholesale price frozen on the line when it was
 *     placed (`product_cost`);
 *   - the Partner's own stock: the wholesale price they actually paid for those
 *     exact units, from the cost lots the sale took them from, first in, first
 *     out;
 *   - the Partner's own stock sold before cost lots existed: the one price the
 *     Partner ever paid for that unit before the sale, when there was one;
 *   - a cost staff set deliberately for the line, when they have;
 *   - and when nothing was frozen at all (orders from before these records),
 *     the product's wholesale price for that quantity as the catalogue holds it.
 *
 * So a base cost is always known. Both sides count only the units that were
 * kept: what came back on a return is taken out of each, the newest lots first.
 */
class StorefrontProfitCalculator
{
    public function __construct(
        protected PartnerStockCostLots $lots,
        protected WholesalePriceResolver $wholesalePrices,
    ) {}

    /**
     * The order's figures from its lines: selling, Partner base cost, the
     * delivery charge that goes to the Partner, the Partner's profit and what
     * Banij keeps. Null cost and profit only when a line's product no longer
     * exists at all.
     *
     * @param  list<array{item: OrderItem, basis: string, quantity: int, returned: int, selling: Money, cost: Money|null, profit: Money|null, unproven: string|null}>  $lines
     * @return array{selling: Money, cost: Money|null, delivery: Money, profit: Money|null, banij_receivable: Money|null}
     */
    public function totals(Order $order, array $lines): array
    {
        $currency = Currency::from($order->currency_code);
        $selling = Money::zero($currency);
        $cost = Money::zero($currency);
        $proven = true;
        $kept = 0;

        foreach ($lines as $line) {
            $selling = $selling->plus($line['selling']);
            $kept += $line['quantity'] - $line['returned'];

            if ($line['cost'] === null) {
                $proven = false;

                continue;
            }

            $cost = $cost->plus($line['cost']);
        }

        // Nothing kept, nothing delivered to keep: no delivery charge either.
        $delivery = $kept > 0 && $order->delivery->isPositive() ? $order->delivery : Money::zero($currency);

        return [
            'selling' => $selling,
            'cost' => $proven ? $cost : null,
            'delivery' => $delivery,
            'profit' => $proven ? $selling->minus($cost)->plus($delivery) : null,
            'banij_receivable' => $proven ? $cost : null,
        ];
    }

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
            if ($item->product_cost !== null) {
                return [PartnerStorefrontProfitLine::BASIS_WHOLESALE_PRICE, $this->lots->share($item->product_cost, $item->quantity, 0, $kept), null];
            }

            return $this->catalogueWholesale($item, $kept);
        }

        $fromLots = $this->lots->keptCost($item, $back);

        if ($fromLots !== null) {
            return [PartnerStorefrontProfitLine::BASIS_FIFO_LOTS, $fromLots, null];
        }

        [$basis, $cost] = $this->historicalSingleCost($order, $item, $kept);

        return $cost !== null ? [$basis, $cost, null] : $this->catalogueWholesale($item, $kept);
    }

    /**
     * The last word on a line nothing frozen prices: the product's wholesale
     * price for the line's quantity, as the catalogue holds it now. Unknown only
     * when the product itself is gone.
     *
     * @return array{0: string, 1: Money|null, 2: string|null}
     */
    protected function catalogueWholesale(OrderItem $item, int $kept): array
    {
        $basis = PartnerStorefrontProfitLine::BASIS_CATALOGUE_WHOLESALE_PRICE;
        $product = Product::withTrashed()->find($item->product_id);

        if (! $product instanceof Product) {
            return [$basis, null, 'product_missing'];
        }

        $variant = $item->product_variant_id === null ? null : ProductVariant::query()->find($item->product_variant_id);
        $whole = $this->wholesalePrices->unitPrice($product, $variant, $item->quantity)->multipliedBy($item->quantity);

        return [$basis, $this->lots->share($whole, $item->quantity, 0, $kept), null];
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
