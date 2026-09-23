<?php

namespace App\Domain\Supplier\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Data\SupplierAllocation;
use App\Domain\Supplier\Exceptions\SupplierAllocationRefused;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Support\Money\Currency;
use Carbon\CarbonImmutable;

/**
 * Which Supplier offer an order line is allocated to (D25, P13-21).
 *
 * **Deterministic and Admin-controlled.** The offer is the one Admin marked
 * preferred for the exact product variation — never the cheapest, never the
 * best-stocked, never a fallback when that one cannot serve. If it cannot, the
 * line is refused, in words that name no Supplier.
 *
 * A variation is *Supplier-sourced* the moment it has any Supplier offer, of
 * any status: from then on its stock comes from Suppliers and only from
 * Suppliers, never partly central. A variation with no offer at all — every
 * product that existed before Suppliers — resolves to `null` and takes the
 * central-stock path unchanged.
 *
 * Everything is read on the ERP server from the offer and its effective-dated
 * price version. Nothing a browser or a Storefront sends is consulted.
 */
class ResolvePreferredOffer
{
    /**
     * Whether the variation's stock is Supplier-sourced.
     */
    public function isSupplierSourced(int $productId, ?int $variantId): bool
    {
        return SupplierOffer::query()
            ->where('product_id', $productId)
            ->when($variantId === null,
                fn ($query) => $query->whereNull('product_variant_id'),
                fn ($query) => $query->where('product_variant_id', $variantId))
            ->exists();
    }

    /**
     * The allocation for `$quantity` units, or `null` when the variation is not
     * Supplier-sourced.
     *
     * @throws SupplierAllocationRefused when it is, and its preferred offer cannot serve
     */
    public function resolve(
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        Currency $currency,
        ?CarbonImmutable $at = null,
    ): ?SupplierAllocation {
        $at ??= CarbonImmutable::now();
        $variantId = $variant?->id;

        if (! $this->isSupplierSourced($product->id, $variantId)) {
            return null;
        }

        /** @var SupplierOffer|null $offer */
        $offer = SupplierOffer::query()
            ->with(['supplier', 'stock'])
            ->where('product_id', $product->id)
            ->when($variantId === null,
                fn ($query) => $query->whereNull('product_variant_id'),
                fn ($query) => $query->where('product_variant_id', $variantId))
            ->where('is_preferred', true)
            ->first();

        if ($offer === null) {
            throw SupplierAllocationRefused::noPreferredOffer();
        }

        if (! $offer->isActive()) {
            throw SupplierAllocationRefused::offerInactive();
        }

        if (! $offer->supplier->isOperational()) {
            throw SupplierAllocationRefused::supplierNotOperational();
        }

        // The price version in force now: the newest one already effective.
        $priceVersion = $offer->priceHistory()->where('effective_from', '<=', $at)->first();

        if ($priceVersion === null || $priceVersion->platform_rate_minor->lessThan($priceVersion->supplier_rate_minor)) {
            throw SupplierAllocationRefused::rateUnavailable();
        }

        if ($priceVersion->supplier_rate_minor->currency !== $priceVersion->platform_rate_minor->currency
            || $priceVersion->supplier_rate_minor->currency !== $currency) {
            throw SupplierAllocationRefused::currencyMismatch();
        }

        $available = $offer->stock->quantity ?? 0;

        if ($available < $quantity) {
            throw SupplierAllocationRefused::insufficientAvailability($available);
        }

        return new SupplierAllocation($offer, $priceVersion, $quantity, $at);
    }
}
