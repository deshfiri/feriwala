<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * Every source that could fulfil one order line, for the staff allocation
 * panel.
 *
 * Read-only, and deliberately opinion-free. It reports what each source holds,
 * what it costs and what the line would earn, and says whether a source
 * *could* serve the line — never which one *should*. Choosing is the staff
 * decision the batch requires, and a query that quietly returned only the
 * cheapest would be making it.
 *
 * Ineligible sources are returned too, with the reason. A panel that silently
 * omits the Supplier a member of staff was expecting to see sends them looking
 * for a bug; one that shows "no stock" or "Supplier suspended" answers the
 * question on the spot.
 *
 * **Staff-only output.** Every candidate carries the Supplier's identity, the
 * Supplier Rate and the margin — the three things D25 keeps out of Client and
 * Partner responses, the Storefront API and partner-facing order views. The
 * caller is responsible for being an authorised staff endpoint; nothing here
 * may be spliced into a customer payload.
 */
class AllocationSourceCandidates
{
    /**
     * @return list<AllocationCandidate>
     */
    public function forLine(OrderItem $line): array
    {
        $currency = Currency::from($line->currency_code);
        $platformRate = $line->unit_price;
        $quantity = (int) $line->quantity;

        $active = OrderItemAllocation::query()
            ->where('order_item_id', $line->id)
            ->where('status', AllocationStatus::Active)
            ->first();

        return [
            ...$this->warehouseCandidates($line, $quantity, $platformRate, $currency, $active),
            ...$this->supplierCandidates($line, $quantity, $platformRate, $currency, $active),
            ...$this->linkedCandidates($line, $quantity, $platformRate, $currency, $active),
        ];
    }

    /**
     * Sources confirmed, through {@see ProductSourceLink}, to fulfil this
     * product/variation despite being catalogued under a different one —
     * "Recommended / Already Related" beyond the trivial exact-match case
     * the two methods above already cover.
     *
     * @return list<AllocationCandidate>
     */
    protected function linkedCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        ?OrderItemAllocation $active,
    ): array {
        $links = ProductSourceLink::query()
            ->forOrderedProduct($line->product_id, $line->product_variant_id)
            ->with(['stockItem.warehouse', 'stockItem.product', 'stockItem.variant', 'supplierOffer.supplier', 'supplierOffer.stock', 'supplierOffer.product', 'supplierOffer.variant'])
            ->get();

        $candidates = [];

        foreach ($links as $link) {
            $candidates[] = $link->source_type === AllocationSourceType::Warehouse
                ? $this->linkedWarehouseCandidate($link, $quantity, $platformRate, $currency, $active)
                : $this->linkedSupplierCandidate($link, $quantity, $platformRate, $currency, $active);
        }

        return array_values(array_filter($candidates));
    }

    protected function linkedWarehouseCandidate(
        ProductSourceLink $link,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        ?OrderItemAllocation $active,
    ): ?AllocationCandidate {
        $item = $link->stockItem;

        if ($item === null || ! $item->warehouse->is_active) {
            return null;
        }

        $variant = $item->product_variant_id === null ? null : $item->variant;
        $cost = $variant === null ? $item->product->base_cost : ($variant->base_cost ?? $item->product->base_cost);
        $atp = (int) $item->available;
        $isEligible = $atp >= $quantity;

        return new AllocationCandidate(
            sourceType: AllocationSourceType::Warehouse,
            sourceId: $item->public_id,
            sourceLabel: $item->warehouse->name,
            available: (int) $item->available,
            reserved: (int) $item->reserved,
            availableToPromise: $atp,
            unitCost: $cost,
            platformRate: $platformRate,
            expectedMargin: $platformRate->minus($cost)->multipliedBy($quantity),
            currencyCode: $currency->value,
            isEligible: $isEligible,
            ineligibleReason: $isEligible ? null : 'This warehouse holds '.$atp.' of the '.$quantity.' needed.',
            isCurrentlyAllocated: $active !== null
                && $active->source_type === AllocationSourceType::Warehouse
                && $active->warehouse_id === $item->warehouse_id,
            isRelated: true,
            sourceProductId: $item->product_id,
            sourceProductVariantId: $item->product_variant_id,
            sourceProductName: $item->product->name,
            sourceProductSku: $item->sku(),
        );
    }

    protected function linkedSupplierCandidate(
        ProductSourceLink $link,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        ?OrderItemAllocation $active,
    ): ?AllocationCandidate {
        $offer = $link->supplierOffer;

        if ($offer === null || $offer->status->value !== 'active' || ! $offer->supplier->isOperational()) {
            return null;
        }

        $stock = $offer->stock;
        $atp = (int) ($stock->quantity ?? 0);
        $rate = $offer->supplier_rate;

        $reason = match (true) {
            $offer->currency_code !== $currency->value => 'This offer is priced in '.$offer->currency_code.'.',
            $atp < $quantity => 'This Supplier has '.$atp.' of the '.$quantity.' needed.',
            default => null,
        };

        return new AllocationCandidate(
            sourceType: AllocationSourceType::SupplierOffer,
            sourceId: $offer->public_id,
            sourceLabel: $offer->supplier->business_name,
            available: $atp,
            reserved: (int) ($stock->reserved_quantity ?? 0),
            availableToPromise: $atp,
            unitCost: $rate,
            platformRate: $platformRate,
            expectedMargin: $offer->currency_code === $currency->value
                ? $platformRate->minus($rate)->multipliedBy((int) $quantity)
                : Money::zero($currency),
            currencyCode: $offer->currency_code,
            isEligible: $reason === null,
            ineligibleReason: $reason,
            isCurrentlyAllocated: $active !== null && $active->supplier_offer_id === $offer->id,
            supplierId: $offer->supplier->public_id,
            supplierName: $offer->supplier->business_name,
            isPreferred: (bool) $offer->is_preferred,
            supplierStatus: $offer->supplier->status->value,
            offerStatus: $offer->status->value,
            isRelated: true,
            sourceProductId: $offer->product_id,
            sourceProductVariantId: $offer->product_variant_id,
            sourceProductName: $offer->product->name,
            sourceProductSku: $offer->variant === null ? $offer->product->sku : $offer->variant->sku,
        );
    }

    /**
     * Every active warehouse holding this SKU.
     *
     * Available-to-promise is the `available` bucket plus whatever this order's
     * own account already has set aside on that item — allocated units have
     * left `available` precisely so nobody else can reach them (§19), so
     * leaving them out would under-report what this order can actually take.
     *
     * @return list<AllocationCandidate>
     */
    protected function warehouseCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        ?OrderItemAllocation $active,
    ): array {
        // A variation may price its own cost; otherwise the product's stands.
        $variant = $line->product_variant_id === null ? null : $line->variant;
        $cost = $variant === null ? $line->product->base_cost : ($variant->base_cost ?? $line->product->base_cost);

        $items = StockItem::query()
            ->with('warehouse')
            ->where('product_id', $line->product_id)
            ->where('product_variant_id', $line->product_variant_id)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->get();

        $setAside = StockAllocation::query()
            ->whereIn('stock_item_id', $items->pluck('id'))
            ->where('business_account_id', $line->order->business_account_id)
            ->pluck('quantity', 'stock_item_id');

        $candidates = $items->map(function (StockItem $item) use (
            $quantity, $cost, $platformRate, $currency, $active, $setAside
        ) {
            $atp = (int) $item->available + (int) ($setAside[$item->id] ?? 0);
            $isEligible = $atp >= $quantity;

            return new AllocationCandidate(
                sourceType: AllocationSourceType::Warehouse,
                sourceId: $item->warehouse->public_id,
                sourceLabel: $item->warehouse->name,
                available: (int) $item->available,
                reserved: (int) $item->reserved,
                availableToPromise: $atp,
                unitCost: $cost,
                platformRate: $platformRate,
                expectedMargin: $platformRate->minus($cost)->multipliedBy($quantity),
                currencyCode: $currency->value,
                isEligible: $isEligible,
                ineligibleReason: $isEligible ? null : 'This warehouse holds '.$atp.' of the '.$quantity.' needed.',
                isCurrentlyAllocated: $active !== null
                    && $active->source_type === AllocationSourceType::Warehouse
                    && $active->warehouse_id === $item->warehouse_id,
            );
        })->values()->all();

        return array_values($candidates);
    }

    /**
     * Every Supplier offering this variation — not only the preferred one.
     *
     * This is where "five Suppliers, one Central Product" becomes visible: the
     * catalogue holds one product and one variation, and each Supplier's offer
     * is a separate row here with its own rate, availability and lead time.
     *
     * @return list<AllocationCandidate>
     */
    protected function supplierCandidates(
        OrderItem $line,
        int $quantity,
        Money $platformRate,
        Currency $currency,
        ?OrderItemAllocation $active,
    ): array {
        $offers = SupplierOffer::query()
            ->with(['supplier', 'stock', 'originatingListingItem'])
            ->where('product_id', $line->product_id)
            ->where('product_variant_id', $line->product_variant_id)
            ->get();

        $candidates = $offers->map(function (SupplierOffer $offer) use (
            $quantity, $platformRate, $currency, $active
        ) {
            $stock = $offer->stock;
            $atp = (int) ($stock->quantity ?? 0);
            $rate = $offer->supplier_rate;

            $reason = match (true) {
                ! $offer->supplier->isOperational() => 'This Supplier is not currently operational.',
                $offer->status->value !== 'active' => 'This offer is suspended.',
                $offer->currency_code !== $currency->value => 'This offer is priced in '.$offer->currency_code.'.',
                $atp < $quantity => 'This Supplier has '.$atp.' of the '.$quantity.' needed.',
                default => null,
            };

            return new AllocationCandidate(
                sourceType: AllocationSourceType::SupplierOffer,
                sourceId: $offer->public_id,
                sourceLabel: $offer->supplier->business_name,
                available: $atp,
                reserved: (int) ($stock->reserved_quantity ?? 0),
                availableToPromise: $atp,
                unitCost: $rate,
                platformRate: $platformRate,
                // Guarded: a mismatched currency would throw inside Money, and
                // this row exists to explain that rather than to fail on it.
                expectedMargin: $offer->currency_code === $currency->value
                    ? $platformRate->minus($rate)->multipliedBy($quantity)
                    : Money::zero($currency),
                currencyCode: $offer->currency_code,
                isEligible: $reason === null,
                ineligibleReason: $reason,
                isCurrentlyAllocated: $active !== null && $active->supplier_offer_id === $offer->id,
                supplierId: $offer->supplier->public_id,
                supplierName: $offer->supplier->business_name,
                leadTimeDays: $offer->originatingListingItem?->lead_time_days,
                isPreferred: (bool) $offer->is_preferred,
                supplierStatus: $offer->supplier->status->value,
                offerStatus: $offer->status->value,
            );
        })->values()->all();

        return array_values($candidates);
    }
}
