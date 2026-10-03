<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Sourcing\Data\SourcingRequirement;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;

/**
 * Works out what an ordered product/variation requires of its fulfilment, from
 * the sourcing mappings that hold **right now** -- called once, when an order
 * line is written, so the answer can be frozen on the line.
 *
 * Returns null (an unmatched line) rather than guessing whenever the answer is
 * not explicit: the product is in no active group, its group is inactive, or
 * the product is a non-canonical member whose ordered variation has no active
 * mapping. An unmatched line is still allocatable by exact product match; it
 * is simply flagged for manual review.
 */
class ResolveSourcingRequirement
{
    public function forLine(int $productId, ?int $variantId): ?SourcingRequirement
    {
        $membership = ProductSourcingGroupProduct::query()
            ->active()
            ->where('product_id', $productId)
            ->with('group')
            ->first();

        // Inactive groups take no new orders.
        if ($membership === null || ! $membership->group->is_active) {
            return null;
        }

        $group = $membership->group;

        // The canonical product defines the requirement: it maps to itself.
        if ($membership->is_canonical) {
            return new SourcingRequirement($group->id, $productId, $variantId);
        }

        $mapping = ProductSourcingVariantMapping::query()
            ->active()
            ->where('sourcing_group_id', $group->id)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($mapping === null) {
            return null;
        }

        $canonicalProductId = ProductSourcingGroupProduct::query()
            ->active()
            ->where('sourcing_group_id', $group->id)
            ->where('is_canonical', true)
            ->value('product_id');

        if ($canonicalProductId === null) {
            return null;
        }

        return new SourcingRequirement($group->id, (int) $canonicalProductId, $mapping->canonical_product_variant_id);
    }
}
