<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use Illuminate\Support\Collection;

/**
 * Works out which Products are the same physical Product as a given one, by
 * following active Same Product links as far as they go.
 *
 * Links are edges of an undirected graph with no master and no order, so this
 * is a breadth-first walk with a visited set: a cycle (A-B, B-C, C-A), two paths
 * to the same Product, or a branch that doubles back all resolve to each
 * Product once, and the walk always ends because each Product is entered at
 * most once. A Product with no links resolves to nothing but itself.
 *
 * Reachability says the **Products** are the same. Whether a particular
 * variation of one is the same as a particular variation of another is a
 * separate, explicit staff decision ({@see ProductLinkVariantMapping}) — see
 * {@see compatiblePairs()}.
 *
 * A trashed Product is out of circulation and is neither returned nor offered
 * as a source.
 */
class ResolveProductNetwork
{
    /**
     * Every other Product in this Product's network, nearest first.
     *
     * `distance` is the fewest links between it and the starting Product;
     * `is_direct` is true when a link joins them directly (a Product may be
     * directly linked and also reachable by a longer path — it is still direct).
     *
     * @return array<int, array{product_id: int, distance: int, is_direct: bool}> keyed by Product id
     */
    public function network(int $productId): array
    {
        ['distances' => $distances] = $this->walk($productId);

        $alive = Product::query()->whereIn('id', array_keys($distances))->pluck('id')->flip();
        $network = [];

        foreach ($distances as $id => $distance) {
            if ($id === $productId || ! $alive->has($id)) {
                continue;
            }

            $network[$id] = ['product_id' => $id, 'distance' => $distance, 'is_direct' => $distance === 1];
        }

        return $network;
    }

    /**
     * The active links that join this Product directly to another.
     *
     * @return list<ProductLink>
     */
    public function directLinks(int $productId): array
    {
        return array_values(ProductLink::query()->active()->touching($productId)
            ->with(['productA', 'productB'])
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Every (Product, variation) that is the same as this one, itself included.
     *
     * A hop across a link keeps the variation only when staff matched it: two
     * Products that both have no variations pass straight through at Product
     * level, but as soon as either has variations a hop needs an active match
     * naming the variation on the way in. A variation with no match simply goes
     * nowhere, so it is never offered as a substitute.
     *
     * @return list<array{0: int, 1: int|null}>
     */
    public function compatiblePairs(int $productId, ?int $variantId): array
    {
        ['distances' => $distances, 'links' => $links] = $this->walk($productId);

        $linkIds = $links->pluck('id')->all();
        $productIds = array_keys($distances);

        $withVariants = ProductVariant::query()
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id')
            ->flip();

        $mappings = ProductLinkVariantMapping::query()->active()
            ->whereIn('product_link_id', $linkIds)
            ->get()
            ->groupBy('product_link_id');

        $linksByProduct = [];

        foreach ($links as $link) {
            $linksByProduct[$link->product_a_id][] = $link;
            $linksByProduct[$link->product_b_id][] = $link;
        }

        $key = fn (int $product, ?int $variant): string => $product.':'.($variant ?? 'x');
        $seen = [$key($productId, $variantId) => [$productId, $variantId]];
        $queue = [[$productId, $variantId]];

        while ($queue !== []) {
            [$product, $variant] = array_shift($queue);

            foreach ($linksByProduct[$product] ?? [] as $link) {
                $isSideA = $link->product_a_id === $product;
                $other = $link->otherProductId($product);
                $reached = [];

                if (! $withVariants->has($product) && ! $withVariants->has($other)) {
                    if ($variant === null) {
                        $reached[] = null;
                    }
                } else {
                    foreach ($mappings->get($link->id, []) as $mapping) {
                        $here = $isSideA ? $mapping->variant_a_id : $mapping->variant_b_id;

                        if ($here === $variant) {
                            $reached[] = $isSideA ? $mapping->variant_b_id : $mapping->variant_a_id;
                        }
                    }
                }

                foreach ($reached as $reachedVariant) {
                    $state = $key($other, $reachedVariant);

                    if (! isset($seen[$state])) {
                        $seen[$state] = [$other, $reachedVariant];
                        $queue[] = [$other, $reachedVariant];
                    }
                }
            }
        }

        $alive = Product::query()->whereIn('id', array_unique(array_column($seen, 0)))->pluck('id')->flip();

        return array_values(array_filter($seen, fn (array $pair) => $alive->has($pair[0])));
    }

    /**
     * Walk the network once, level by level.
     *
     * @return array{distances: array<int, int>, links: Collection<int, ProductLink>}
     */
    protected function walk(int $productId): array
    {
        $distances = [$productId => 0];
        $links = collect();
        $frontier = [$productId];
        $distance = 0;

        while ($frontier !== []) {
            $distance++;

            $found = ProductLink::query()->active()
                ->where(fn ($query) => $query->whereIn('product_a_id', $frontier)->orWhereIn('product_b_id', $frontier))
                ->get(['id', 'product_a_id', 'product_b_id']);

            $next = [];

            foreach ($found as $link) {
                $links->put($link->id, $link);

                foreach ([$link->product_a_id, $link->product_b_id] as $end) {
                    if (! isset($distances[$end])) {
                        $distances[$end] = $distance;
                        $next[] = $end;
                    }
                }
            }

            $frontier = $next;
        }

        return ['distances' => $distances, 'links' => $links];
    }
}
