<?php

namespace App\Domain\Supplier\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Models\SupplierProductListingItem;
use Illuminate\Support\Collection;

/**
 * Suggests which of a product's existing variations a proposed variation
 * might be (Supplier Bulk Product Listing batch) -- a hint for the reviewer
 * to look at first, never a write. Staff still connects a variation only
 * through {@see DecideSupplierListing}'s own `variant_id` input; nothing
 * here creates or changes a {@see ProductVariant}.
 *
 * Ranked by attribute-value overlap first (the strongest signal a Supplier's
 * structured Size/Colour selection can give), then by an exact SKU match --
 * a Supplier's own SKU has no reason to resemble the catalogue's.
 */
class MatchSupplierListingItemToVariant
{
    /**
     * @return Collection<int, ProductVariant>
     */
    public function handle(Product $product, SupplierProductListingItem $item): Collection
    {
        $proposedValueIds = $item->attributeValues()->pluck('product_attribute_values.id')->all();

        return $product->variants()->with('values')->get()
            ->map(fn (ProductVariant $variant) => [
                'variant' => $variant,
                'overlap' => count(array_intersect($variant->values->pluck('id')->all(), $proposedValueIds)),
                'sku_match' => mb_strtolower($variant->sku) === mb_strtolower($item->supplier_sku),
            ])
            ->filter(fn (array $candidate) => $candidate['overlap'] > 0 || $candidate['sku_match'])
            ->sortByDesc(fn (array $candidate) => $candidate['overlap'] * 10 + ($candidate['sku_match'] ? 1 : 0))
            ->pluck('variant')
            ->values();
    }
}
