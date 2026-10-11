<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Policies\ProductSupplierPolicy;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;

/**
 * What the "Suppliers" section of the Admin Product workspace and the
 * "Products" section of the Supplier page show: the Supplier Offers, which are
 * the one record of a Supplier holding a Product, whichever way it got there.
 *
 * A Supplier Base Price is confidential pricing (D25): it is present only for
 * staff who may view Supplier pricing, and `null` for everyone else. The
 * platform's own rate never appears here.
 *
 * Staff-only, and null for anyone who may not view the Suppliers of a Product.
 */
class ProductSuppliersSection
{
    /**
     * @return array<string, mixed>|null
     */
    public function forProduct(Product $product, User $viewer): ?array
    {
        if (! ProductSupplierPolicy::canView($viewer)) {
            return null;
        }

        $showPrices = ProductSupplierPolicy::canViewPrices($viewer);

        $offers = SupplierOffer::query()
            ->with(['supplier', 'variant.values', 'stock', 'assigner:id,name'])
            ->where('product_id', $product->id)
            ->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderBy('id')
            ->get();

        $variants = $product->variants()->with('values')->where('is_active', true)->orderBy('id')->get();

        return [
            'offers' => $offers->map(fn (SupplierOffer $offer) => $this->row($offer, $showPrices, withProduct: false))->values()->all(),
            'variants' => $variants->map(fn (ProductVariant $variant) => ['id' => $variant->public_id, 'label' => $variant->label()])->values()->all(),
            'can' => [
                'assign' => ProductSupplierPolicy::canAssign($viewer),
                'edit' => ProductSupplierPolicy::canEditOffer($viewer),
                'view_prices' => $showPrices,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forSupplier(Supplier $supplier, User $viewer): ?array
    {
        if (! ProductSupplierPolicy::canView($viewer)) {
            return null;
        }

        $showPrices = ProductSupplierPolicy::canViewPrices($viewer);

        $offers = SupplierOffer::query()
            ->with(['product:id,public_id,name,sku', 'variant.values', 'stock', 'assigner:id,name'])
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('id')
            ->get();

        return [
            'offers' => $offers->map(fn (SupplierOffer $offer) => $this->row($offer, $showPrices, withProduct: true))->values()->all(),
            'can' => ['view_prices' => $showPrices],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierOffer $offer, bool $showPrices, bool $withProduct): array
    {
        return [
            'id' => $offer->public_id,
            'reference' => $offer->reference,
            'supplier' => $withProduct ? null : $this->supplier($offer->supplier),
            'product' => $withProduct ? [
                'id' => $offer->product->public_id,
                'name' => $offer->product->name,
                'bpc' => $offer->product->sku,
            ] : null,
            'variant' => $offer->variant === null ? null : [
                'id' => $offer->variant->public_id,
                'label' => $offer->variant->label(),
                'sku' => $offer->variant->sku,
            ],
            'supplier_base_price' => $showPrices ? $offer->supplier_rate->jsonSerialize() : null,
            'supply_mode' => $offer->supply_mode->value,
            'supply_mode_label' => $offer->supply_mode->label(),
            'available_quantity' => $offer->stock?->quantity,
            'fulfilment_capacity' => $offer->fulfilment_capacity,
            'lead_time_days' => $offer->lead_time_days,
            'status' => $offer->status->value,
            'status_label' => $offer->status->label(),
            'status_tone' => $offer->status->tone(),
            'origin' => $offer->origin->value,
            'origin_label' => $offer->origin->label(),
            'assigned_by' => $offer->assigner?->name,
            'assigned_at' => $offer->assigned_at?->toIso8601String(),
            'updated_at' => $offer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, reference: string, name: string, status: string, status_label: string, status_tone: string}
     */
    public function supplier(Supplier $supplier): array
    {
        return [
            'id' => $supplier->public_id,
            'reference' => $supplier->reference,
            'name' => $supplier->business_name,
            'status' => $supplier->status->value,
            'status_label' => $supplier->status->label(),
            'status_tone' => $supplier->status->tone(),
        ];
    }
}
