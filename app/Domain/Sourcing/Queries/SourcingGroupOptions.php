<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Models\User;

/**
 * What the Supplier listing review screens need to let staff pick a sourcing
 * group: the active groups with their canonical product and variations, the
 * group the connected product is already in, and what the viewer may do.
 *
 * Platform-staff output only. A Supplier never receives any of it.
 */
class SourcingGroupOptions
{
    /**
     * @return array{groups: array<int, array<string, mixed>>, can_select: bool, can_create: bool, connected_group: string|null}
     */
    public function forReview(User $viewer, ?Product $connectedProduct): array
    {
        $groups = ProductSourcingGroup::query()
            ->active()
            ->orderBy('name_en')
            ->limit(300)
            ->get();

        $canonicals = ProductSourcingGroupProduct::query()
            ->active()
            ->where('is_canonical', true)
            ->whereIn('sourcing_group_id', $groups->pluck('id'))
            ->with(['product.variants.values'])
            ->get()
            ->keyBy('sourcing_group_id');

        $connected = $connectedProduct === null ? null : ProductSourcingGroupProduct::query()
            ->active()
            ->where('product_id', $connectedProduct->id)
            ->with('group')
            ->first();

        return [
            'groups' => $groups->map(function (ProductSourcingGroup $group) use ($canonicals) {
                $canonical = $canonicals->get($group->id)?->product;

                return [
                    'id' => $group->public_id,
                    'code' => $group->code,
                    'name_en' => $group->name_en,
                    'name_bn' => $group->name_bn,
                    'canonical_product' => $canonical === null ? null : ['name' => $canonical->name, 'sku' => $canonical->sku],
                    'canonical_variants' => $canonical === null ? [] : $canonical->variants->map(fn (ProductVariant $variant) => [
                        'id' => $variant->public_id,
                        'label' => $variant->label(),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'can_select' => $viewer->can('update', new ProductSourcingGroup),
            'can_create' => $viewer->can('create', ProductSourcingGroup::class),
            'connected_group' => $connected?->group->public_id,
        ];
    }
}
