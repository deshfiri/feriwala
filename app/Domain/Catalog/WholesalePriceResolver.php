<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money\Money;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What one unit costs a business account at a given quantity (§11.1, §14).
 *
 * The one place the tier arithmetic happens, on the server (§36.1). The cart
 * and checkout (P4-5) ask here; no screen works a tier out for itself.
 *
 * Which tiers apply: a variation's own, when it has any; otherwise the
 * product's. The base is the variation's effective wholesale price — its own
 * override, or the product's.
 *
 * **Buying more never costs more per unit.** A tier is written against the base
 * price of the day, and the base can be cut later. When that leaves a tier above
 * the base, the base is what is charged: the tier was a discount, and a discount
 * that became a surcharge is not what anybody set up.
 */
class WholesalePriceResolver
{
    public function unitPrice(Product $product, ?ProductVariant $variant, int $quantity): Money
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException("A quantity of {$quantity} has no unit price.");
        }

        $base = $this->basePrice($product, $variant);

        $tier = $this->tiersFor($product, $variant)
            ->filter(fn (ProductPriceTier $tier) => $tier->min_quantity <= $quantity)
            ->sortByDesc('min_quantity')
            ->first();

        if (! $tier instanceof ProductPriceTier) {
            return $base;
        }

        return $tier->unit_price->lessThan($base) ? $tier->unit_price : $base;
    }

    public function basePrice(Product $product, ?ProductVariant $variant): Money
    {
        return $variant?->effectiveWholesalePrice() ?? $product->wholesale_price;
    }

    /**
     * The tiers that govern this product or variation, lowest quantity first.
     *
     * @return Collection<int, ProductPriceTier>
     */
    public function tiersFor(Product $product, ?ProductVariant $variant): Collection
    {
        if ($variant !== null) {
            $own = ProductPriceTier::query()
                ->where('product_variant_id', $variant->id)
                ->orderBy('min_quantity')
                ->get();

            if ($own->isNotEmpty()) {
                return $own->toBase();
            }
        }

        return ProductPriceTier::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->orderBy('min_quantity')
            ->get()
            ->toBase();
    }
}
