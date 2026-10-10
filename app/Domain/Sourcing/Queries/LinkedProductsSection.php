<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use App\Domain\Sourcing\Policies\ProductLinkPolicy;
use App\Models\User;

/**
 * Everything the "Linked Products" section of the Admin Product workspace
 * shows: the Product itself, its direct connections (each unlinkable, each with
 * its variation matches) and every other Product reachable only through them.
 *
 * Staff-only, and null for anyone who may not view links.
 */
class LinkedProductsSection
{
    public function __construct(
        protected ResolveProductNetwork $network,
        protected ProductLinkSummaries $summaries,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(Product $product, User $viewer): ?array
    {
        if (! ProductLinkPolicy::canView($viewer)) {
            return null;
        }

        $links = collect($this->network->directLinks($product->id))
            ->filter(fn (ProductLink $link) => $link->productA !== null && $link->productB !== null)
            ->values();

        $network = $this->network->network($product->id);

        $others = Product::query()->whereIn('id', array_keys($network))->get()->keyBy('id');
        $summaries = $this->summaries->for($others->push($product)->values());

        $mappings = ProductLinkVariantMapping::query()->active()
            ->with(['variantA.values', 'variantB.values'])
            ->whereIn('product_link_id', $links->pluck('id'))
            ->get()
            ->groupBy('product_link_id');

        $variantOptions = fn (int $productId) => ProductVariant::query()->with('values')
            ->where('product_id', $productId)
            ->orderBy('id')
            ->get()
            ->map(fn (ProductVariant $variant) => ['id' => $variant->public_id, 'label' => $variant->label()])
            ->all();

        $direct = $links->map(function (ProductLink $link) use ($product, $summaries, $mappings, $variantOptions) {
            $otherId = $link->otherProductId($product->id);
            $mineIsA = $link->product_a_id === $product->id;

            return [
                'id' => $link->public_id,
                'product' => $summaries[$otherId],
                'linked_by' => $link->linker?->name,
                'linked_at' => $link->linked_at->toIso8601String(),
                'reason' => $link->link_reason,
                'my_variants' => $variantOptions($product->id),
                'their_variants' => $variantOptions($otherId),
                'variant_matches' => $mappings->get($link->id, collect())->map(function (ProductLinkVariantMapping $mapping) use ($mineIsA) {
                    $mine = $mineIsA ? $mapping->variantA : $mapping->variantB;
                    $theirs = $mineIsA ? $mapping->variantB : $mapping->variantA;

                    return [
                        'id' => $mapping->public_id,
                        'mine' => $mine === null ? null : ['id' => $mine->public_id, 'label' => $mine->label()],
                        'theirs' => $theirs === null ? null : ['id' => $theirs->public_id, 'label' => $theirs->label()],
                    ];
                })->values()->all(),
            ];
        })->all();

        // Reachable only through some other Product. A Product that is also
        // directly linked is shown above, once.
        $indirect = collect($network)
            ->reject(fn (array $entry) => $entry['is_direct'])
            ->sortBy('distance')
            ->map(fn (array $entry) => [
                'product' => $summaries[$entry['product_id']],
                'distance' => $entry['distance'],
            ])
            ->values()
            ->all();

        return [
            'current' => $summaries[$product->id],
            'direct' => $direct,
            'indirect' => $indirect,
            'can' => [
                'link' => ProductLinkPolicy::canLink($viewer),
                'unlink' => ProductLinkPolicy::canUnlink($viewer),
            ],
        ];
    }
}
