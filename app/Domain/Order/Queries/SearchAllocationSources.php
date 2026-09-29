<?php

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Data\AllocationCandidate;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductSourceLink;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The full eligible Supplier-offer and warehouse-stock catalogue, searchable
 * by product, SKU, variant, Supplier or warehouse — not limited to a source
 * already catalogued under the ordered product/variation.
 *
 * Every other candidate a staff member could reach without first confirming
 * a {@see ProductSourceLink}
 * ({@see AllocationSourceCandidates} already covers "Recommended / Already
 * Related": the exact-match case plus confirmed links). This exists so
 * staff can find a source that legitimately fulfils the ordered item but
 * happens to be catalogued differently, without ever being handed the whole
 * catalogue unpaginated.
 */
class SearchAllocationSources
{
    private const PER_PAGE = 20;

    /**
     * @return LengthAwarePaginator<int, AllocationCandidate>
     */
    public function search(OrderItem $line, ?AllocationSourceType $type, string $query, int $page): LengthAwarePaginator
    {
        $query = trim($query);
        $currency = Currency::from($line->currency_code);

        $linkedIds = ProductSourceLink::query()
            ->forOrderedProduct($line->product_id, $line->product_variant_id)
            ->get(['source_type', 'warehouse_stock_item_id', 'supplier_offer_id']);

        $linkedStockItemIds = array_values($linkedIds->where('source_type', AllocationSourceType::Warehouse)->pluck('warehouse_stock_item_id')->all());
        $linkedOfferIds = array_values($linkedIds->where('source_type', AllocationSourceType::SupplierOffer)->pluck('supplier_offer_id')->all());

        $warehouseCandidates = $type === AllocationSourceType::SupplierOffer ? collect() : $this->warehouseResults($line, $query, $linkedStockItemIds);
        $supplierCandidates = $type === AllocationSourceType::Warehouse ? collect() : $this->supplierResults($line, $currency, $query, $linkedOfferIds);

        $all = $warehouseCandidates->concat($supplierCandidates)->values();

        $slice = $all->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();

        return new LengthAwarePaginator($slice, $all->count(), self::PER_PAGE, $page);
    }

    /**
     * @param  list<int>  $linkedStockItemIds
     * @return Collection<int, AllocationCandidate>
     */
    protected function warehouseResults(OrderItem $line, string $query, array $linkedStockItemIds): Collection
    {
        $items = StockItem::query()
            ->with(['warehouse', 'product', 'variant'])
            ->whereHas('warehouse', fn ($q) => $q->where('is_active', true))
            ->when($query !== '', fn ($q) => $q->where(function ($q) use ($query) {
                $q->whereHas('product', fn ($q2) => $q2->where('name', 'ilike', "%{$query}%")->orWhere('sku', 'ilike', "%{$query}%"))
                    ->orWhereHas('variant', fn ($q2) => $q2->where('sku', 'ilike', "%{$query}%"))
                    ->orWhereHas('warehouse', fn ($q2) => $q2->where('name', 'ilike', "%{$query}%"));
            }))
            ->orderBy('id')
            ->limit(500)
            ->get();

        return $items->map(function (StockItem $item) use ($line, $linkedStockItemIds) {
            $isExactMatch = $item->product_id === $line->product_id && $item->product_variant_id === $line->product_variant_id;
            $variant = $item->product_variant_id === null ? null : $item->variant;
            $cost = $variant === null ? $item->product->base_cost : ($variant->base_cost ?? $item->product->base_cost);
            $quantity = (int) $line->quantity;
            $atp = (int) $item->available;
            $isEligible = $atp >= $quantity;

            return new AllocationCandidate(
                sourceType: AllocationSourceType::Warehouse,
                sourceId: $isExactMatch ? $item->warehouse->public_id : $item->public_id,
                sourceLabel: $item->warehouse->name,
                available: (int) $item->available,
                reserved: (int) $item->reserved,
                availableToPromise: $atp,
                unitCost: $cost,
                platformRate: $line->unit_price,
                expectedMargin: $line->unit_price->minus($cost)->multipliedBy($quantity),
                currencyCode: $line->currency_code,
                isEligible: $isEligible,
                ineligibleReason: $isEligible ? null : 'This warehouse holds '.$atp.' of the '.$quantity.' needed.',
                isRelated: $isExactMatch || in_array($item->id, $linkedStockItemIds, true),
                sourceProductId: $item->product_id,
                sourceProductVariantId: $item->product_variant_id,
                sourceProductName: $item->product->name,
                sourceProductSku: $item->sku(),
            );
        });
    }

    /**
     * @param  list<int>  $linkedOfferIds
     * @return Collection<int, AllocationCandidate>
     */
    protected function supplierResults(OrderItem $line, Currency $currency, string $query, array $linkedOfferIds): Collection
    {
        $offers = SupplierOffer::query()
            ->with(['supplier', 'stock', 'product', 'variant'])
            ->where('status', 'active')
            ->when($query !== '', fn ($q) => $q->where(function ($q) use ($query) {
                $q->whereHas('product', fn ($q2) => $q2->where('name', 'ilike', "%{$query}%")->orWhere('sku', 'ilike', "%{$query}%"))
                    ->orWhereHas('variant', fn ($q2) => $q2->where('sku', 'ilike', "%{$query}%"))
                    ->orWhereHas('supplier', fn ($q2) => $q2->where('business_name', 'ilike', "%{$query}%"));
            }))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(fn (SupplierOffer $offer) => $offer->supplier->isOperational());

        return $offers->map(function (SupplierOffer $offer) use ($line, $currency, $linkedOfferIds) {
            $isExactMatch = $offer->product_id === $line->product_id && $offer->product_variant_id === $line->product_variant_id;
            $stock = $offer->stock;
            $atp = (int) ($stock->quantity ?? 0);
            $quantity = (int) $line->quantity;
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
                platformRate: $line->unit_price,
                expectedMargin: $offer->currency_code === $currency->value
                    ? $line->unit_price->minus($rate)->multipliedBy($quantity)
                    : Money::zero($currency),
                currencyCode: $offer->currency_code,
                isEligible: $reason === null,
                ineligibleReason: $reason,
                supplierId: $offer->supplier->public_id,
                supplierName: $offer->supplier->business_name,
                isPreferred: (bool) $offer->is_preferred,
                supplierStatus: $offer->supplier->status->value,
                offerStatus: $offer->status->value,
                isRelated: $isExactMatch || in_array($offer->id, $linkedOfferIds, true),
                sourceProductId: $offer->product_id,
                sourceProductVariantId: $offer->product_variant_id,
                sourceProductName: $offer->product->name,
                sourceProductSku: $offer->variant === null ? $offer->product->sku : $offer->variant->sku,
            );
        });
    }
}
