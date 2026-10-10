<?php

namespace App\Domain\Sourcing\Actions;

use App\Domain\Sourcing\Enums\ProductLinkStatus;
use App\Domain\Sourcing\Enums\SourcingMappingStatus;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Sourcing\Models\ProductSourcingGroupProduct;
use App\Domain\Sourcing\Models\ProductSourcingVariantMapping;
use Illuminate\Database\DatabaseManager;

/**
 * One-off transition: turns what Product Sourcing Groups already said into
 * direct Same Product links, so nothing that was matched by a group stops being
 * matched when allocation stops reading groups.
 *
 * Each active group with two or more active members becomes links between its
 * canonical Product and every other member (a group with one Product says
 * nothing and needs no link), and each active variation mapping becomes a
 * variation match on that link. The canonical product is only the star's
 * centre here — in the link network it has no special standing.
 *
 * Idempotent: a pair already linked, or a variation pair already matched, is
 * left alone, so running it twice (or after staff have linked some of the pairs
 * by hand) creates nothing new. Groups, memberships and mappings are never
 * touched — they are protected history and stay exactly as they are. Inactive
 * groups are skipped: they took no new orders, so they asserted nothing current.
 */
class ConvertSourcingGroupsToProductLinks
{
    public function __construct(protected DatabaseManager $database) {}

    /**
     * @return int how many links were created
     */
    public function handle(): int
    {
        $created = 0;

        $groups = ProductSourcingGroup::query()->where('is_active', true)->orderBy('id')->get();

        foreach ($groups as $group) {
            $members = ProductSourcingGroupProduct::query()->active()->where('sourcing_group_id', $group->id)->get();
            $canonical = $members->firstWhere('is_canonical', true);

            if ($canonical === null || $members->count() < 2) {
                continue;
            }

            foreach ($members as $member) {
                if ($member->product_id === $canonical->product_id) {
                    continue;
                }

                $this->database->transaction(function () use ($group, $canonical, $member, &$created) {
                    [$a, $b] = $canonical->product_id < $member->product_id
                        ? [$canonical->product_id, $member->product_id]
                        : [$member->product_id, $canonical->product_id];

                    $link = ProductLink::query()->active()->where('product_a_id', $a)->where('product_b_id', $b)->first();

                    if ($link === null) {
                        $link = ProductLink::create([
                            'product_a_id' => $a,
                            'product_b_id' => $b,
                            'status' => ProductLinkStatus::Active,
                            'linked_by' => $member->added_by,
                            'linked_at' => $member->created_at ?? now(),
                            'link_reason' => 'Converted from sourcing group "'.$group->code.'".',
                        ]);

                        $created++;
                    }

                    $this->convertMappings($group, $member, $canonical, $link);
                });
            }
        }

        return $created;
    }

    protected function convertMappings(
        ProductSourcingGroup $group,
        ProductSourcingGroupProduct $member,
        ProductSourcingGroupProduct $canonical,
        ProductLink $link,
    ): void {
        $mappings = ProductSourcingVariantMapping::query()->active()
            ->where('sourcing_group_id', $group->id)
            ->where('product_id', $member->product_id)
            ->get();

        foreach ($mappings as $mapping) {
            // Neither side has variations: the Product-level link says it all.
            if ($mapping->product_variant_id === null && $mapping->canonical_product_variant_id === null) {
                continue;
            }

            $memberIsA = $link->product_a_id === $member->product_id;
            $variantA = $memberIsA ? $mapping->product_variant_id : $mapping->canonical_product_variant_id;
            $variantB = $memberIsA ? $mapping->canonical_product_variant_id : $mapping->product_variant_id;

            $exists = ProductLinkVariantMapping::query()->active()
                ->where('product_link_id', $link->id)
                ->where('variant_a_id', $variantA)
                ->where('variant_b_id', $variantB)
                ->exists();

            if ($exists) {
                continue;
            }

            ProductLinkVariantMapping::create([
                'product_link_id' => $link->id,
                'variant_a_id' => $variantA,
                'variant_b_id' => $variantB,
                'status' => SourcingMappingStatus::Active,
                'mapped_by' => $mapping->added_by,
                'mapped_at' => $mapping->created_at ?? now(),
                'map_reason' => 'Converted from sourcing group "'.$group->code.'".',
            ]);
        }
    }
}
