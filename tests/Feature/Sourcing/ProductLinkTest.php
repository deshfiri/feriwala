<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Actions\ConvertSourcingGroupsToProductLinks;
use App\Domain\Sourcing\Actions\ManageProductLinks;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Enums\ProductLinkStatus;
use App\Domain\Sourcing\Exceptions\ProductLinkRefused;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use App\Domain\Sourcing\Queries\ResolveProductNetwork;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/*
 * Same Product links: a staff-confirmed, bidirectional network of independent
 * Product records. No master, no parent, no order -- any Product may link to
 * any other, and the network is whatever can be reached.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->links = app(ManageProductLinks::class);
    $this->network = app(ResolveProductNetwork::class);
});

function productLinkTestProduct(string $name = 'Cotton Pants'): Product
{
    return websiteTestProduct(['name' => $name, 'sku' => 'L'.Str::upper(Str::random(8))]);
}

function productLinkTestVariant(Product $product, string $sku): ProductVariant
{
    return ProductVariant::create(['product_id' => $product->id, 'sku' => $sku.Str::upper(Str::random(4)), 'combination_key' => $sku.Str::random(6)]);
}

describe('linking', function () {
    it('links two Products in either direction and stores the pair once', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();

        $link = $this->links->link($this->staff, $b, $a, 'Same item, two Suppliers.');

        expect($link->product_a_id)->toBe(min($a->id, $b->id))
            ->and($link->product_b_id)->toBe(max($a->id, $b->id))
            ->and($link->linked_by)->toBe($this->staff->id)
            ->and($link->link_reason)->toBe('Same item, two Suppliers.')
            ->and($link->status)->toBe(ProductLinkStatus::Active)
            ->and(array_keys($this->network->network($a->id)))->toBe([$b->id])
            ->and(array_keys($this->network->network($b->id)))->toBe([$a->id]);
    });

    it('refuses a Product linked to itself', function () {
        $a = productLinkTestProduct();

        expect(fn () => $this->links->link($this->staff, $a, $a))->toThrow(ProductLinkRefused::class);
        expect(ProductLink::query()->count())->toBe(0);
    });

    it('refuses the same pair a second time, in either order', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();
        $this->links->link($this->staff, $a, $b);

        expect(fn () => $this->links->link($this->staff, $b, $a))->toThrow(ProductLinkRefused::class)
            ->and(fn () => $this->links->link($this->staff, $a, $b))->toThrow(ProductLinkRefused::class);
        expect(ProductLink::query()->count())->toBe(1);
    });

    it('holds the ordered, unique pair in the database too', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();
        [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];

        expect(fn () => ProductLink::create([
            'product_a_id' => $high->id, 'product_b_id' => $low->id,
            'status' => 'active', 'linked_by' => $this->staff->id, 'linked_at' => now(),
        ]))->toThrow(QueryException::class, 'product_same_links_ordered_pair');
    });

    it('lets staff unlink one direct connection and keeps the row as history', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();
        $link = $this->links->link($this->staff, $a, $b);

        $this->links->unlink($this->staff, $link, 'Not the same after all.');

        $link->refresh();

        expect($link->status)->toBe(ProductLinkStatus::Unlinked)
            ->and($link->unlinked_by)->toBe($this->staff->id)
            ->and($link->unlink_reason)->toBe('Not the same after all.')
            ->and($this->network->network($a->id))->toBe([]);

        // The pair can be confirmed again later: a fresh row, the old one stays.
        $this->links->link($this->staff, $a, $b);
        expect(ProductLink::query()->count())->toBe(2);
    });

    // One database violation per test: Postgres aborts the surrounding
    // transaction on the first.
    it('never deletes a link row', function () {
        $link = $this->links->link($this->staff, productLinkTestProduct(), productLinkTestProduct());

        expect(fn () => $link->delete())->toThrow(QueryException::class, 'never deleted');
    });

    it('is refused to staff without the permission', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();

        expect(fn () => $this->links->link($viewer, $a, $b))->toThrow(AuthorizationException::class);

        $link = $this->links->link($this->staff, $a, $b);

        expect(fn () => $this->links->unlink($viewer, $link))->toThrow(AuthorizationException::class)
            ->and($link->refresh()->status)->toBe(ProductLinkStatus::Active);
    });
});

describe('resolving the network', function () {
    it('follows direct, indirect and branching connections, nearest first', function () {
        // A-B, A-C, B-D: no required sequence, no parent.
        [$a, $b, $c, $d, $loner] = array_map(fn ($n) => productLinkTestProduct("P{$n}"), [1, 2, 3, 4, 5]);
        $this->links->link($this->staff, $a, $b);
        $this->links->link($this->staff, $c, $a);
        $this->links->link($this->staff, $b, $d);

        $fromA = $this->network->network($a->id);
        $fromD = $this->network->network($d->id);

        expect(array_keys($fromA))->toEqualCanonicalizing([$b->id, $c->id, $d->id])
            ->and($fromA[$b->id]['is_direct'])->toBeTrue()
            ->and($fromA[$d->id])->toMatchArray(['distance' => 2, 'is_direct' => false])
            ->and(array_keys($fromD))->toEqualCanonicalizing([$a->id, $b->id, $c->id])
            ->and($this->network->network($loner->id))->toBe([]);
    });

    it('is safe on a circle and lists a Product reachable by two paths once', function () {
        [$a, $b, $c] = array_map(fn ($n) => productLinkTestProduct("C{$n}"), [1, 2, 3]);
        $this->links->link($this->staff, $a, $b);
        $this->links->link($this->staff, $b, $c);
        $this->links->link($this->staff, $c, $a);

        $fromA = $this->network->network($a->id);

        expect(array_keys($fromA))->toEqualCanonicalizing([$b->id, $c->id])
            // C is reachable via B and directly: it is direct.
            ->and($fromA[$c->id])->toMatchArray(['distance' => 1, 'is_direct' => true])
            ->and($this->network->compatiblePairs($a->id, null))->toHaveCount(3);
    });

    it('leaves a trashed Product out of the network', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();
        $this->links->link($this->staff, $a, $b);

        $b->delete();

        expect($this->network->network($a->id))->toBe([]);
    });
});

describe('variation compatibility', function () {
    it('passes Products without variations straight through, but never an unmatched variation', function () {
        $plainA = productLinkTestProduct();
        $plainB = productLinkTestProduct();
        $this->links->link($this->staff, $plainA, $plainB);

        expect($this->network->compatiblePairs($plainA->id, null))->toEqualCanonicalizing([[$plainA->id, null], [$plainB->id, null]]);

        $shirtA = productLinkTestProduct('Shirt A');
        $shirtB = productLinkTestProduct('Shirt B');
        $blackXlA = productLinkTestVariant($shirtA, 'BLK-XL');
        $blackXlB = productLinkTestVariant($shirtB, 'BLK-XL');
        $blueMB = productLinkTestVariant($shirtB, 'BLU-M');
        $link = $this->links->link($this->staff, $shirtA, $shirtB);

        // Same label on both sides means nothing until staff match them.
        expect($this->network->compatiblePairs($shirtA->id, $blackXlA->id))->toBe([[$shirtA->id, $blackXlA->id]]);

        $sideA = $link->product_a_id === $shirtA->id;
        $this->links->mapVariants($this->staff, $link, $sideA ? $blackXlA : $blackXlB, $sideA ? $blackXlB : $blackXlA);

        expect($this->network->compatiblePairs($shirtA->id, $blackXlA->id))->toEqualCanonicalizing([[$shirtA->id, $blackXlA->id], [$shirtB->id, $blackXlB->id]])
            ->and($this->network->compatiblePairs($shirtB->id, $blueMB->id))->toBe([[$shirtB->id, $blueMB->id]]);
    });

    it('chains matches across an indirect connection and stops where a match is missing', function () {
        [$a, $b, $c] = array_map(fn ($n) => productLinkTestProduct("V{$n}"), [1, 2, 3]);
        $va = productLinkTestVariant($a, 'M');
        $vb = productLinkTestVariant($b, 'M');
        $vc = productLinkTestVariant($c, 'M');
        $ab = $this->links->link($this->staff, $a, $b);
        $bc = $this->links->link($this->staff, $b, $c);

        $matchPair = function (ProductLink $link, Product $first, ProductVariant $firstVariant, ProductVariant $secondVariant) {
            $firstIsA = $link->product_a_id === $first->id;
            $this->links->mapVariants($this->staff, $link, $firstIsA ? $firstVariant : $secondVariant, $firstIsA ? $secondVariant : $firstVariant);
        };

        $matchPair($ab, $a, $va, $vb);

        // B-C has no match yet, so C's variation is not a substitute for A's.
        expect(collect($this->network->compatiblePairs($a->id, $va->id))->pluck(0)->all())->toEqualCanonicalizing([$a->id, $b->id]);

        $matchPair($bc, $b, $vb, $vc);

        expect(collect($this->network->compatiblePairs($a->id, $va->id))->pluck(0)->all())->toEqualCanonicalizing([$a->id, $b->id, $c->id]);
    });

    it('refuses a variation of the wrong Product, or a missing one where the Product has variations', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();
        $va = productLinkTestVariant($a, 'X');
        $link = $this->links->link($this->staff, $a, $b);
        $sideA = $link->product_a_id === $a->id;

        // A has variations and was given none; B has none and was given one of A's.
        expect(fn () => $this->links->mapVariants($this->staff, $link, $sideA ? null : $va, $sideA ? $va : null))
            ->toThrow(ProductLinkRefused::class);
        expect(ProductLinkVariantMapping::query()->count())->toBe(0);
    });

    it('drops variation matches when the link is unlinked', function () {
        $a = productLinkTestProduct();
        $b = productLinkTestProduct();
        $va = productLinkTestVariant($a, 'M');
        $vb = productLinkTestVariant($b, 'M');
        $link = $this->links->link($this->staff, $a, $b);
        $sideA = $link->product_a_id === $a->id;
        $this->links->mapVariants($this->staff, $link, $sideA ? $va : $vb, $sideA ? $vb : $va);

        $this->links->unlink($this->staff, $link);

        expect(ProductLinkVariantMapping::query()->active()->count())->toBe(0)
            ->and(ProductLinkVariantMapping::query()->count())->toBe(1);
    });
});

describe('transition from sourcing groups', function () {
    it('turns an active group into direct links with its variation mappings, idempotently', function () {
        $groups = app(ManageSourcingGroups::class);
        $group = $groups->create($this->staff, ['code' => 'pants-'.Str::lower(Str::random(5)), 'name_en' => 'Pants', 'name_bn' => 'প্যান্ট']);

        $canonical = productLinkTestProduct('Canonical');
        $member = productLinkTestProduct('Member');
        $solo = productLinkTestProduct('Solo');
        $canonicalM = productLinkTestVariant($canonical, 'M');
        $memberM = productLinkTestVariant($member, 'M');

        $groups->addProduct($this->staff, $group, $canonical, 'Reference.');
        $groups->addProduct($this->staff, $group, $member, 'Same.');
        $groups->mapVariant($this->staff, $group, $member, $memberM, $canonicalM, 'Same fit.');

        $soloGroup = $groups->create($this->staff, ['code' => 'solo-'.Str::lower(Str::random(5)), 'name_en' => 'Solo', 'name_bn' => 'একা']);
        $groups->addProduct($this->staff, $soloGroup, $solo, 'Only one.');

        $created = app(ConvertSourcingGroupsToProductLinks::class)->handle();

        expect($created)->toBe(1)
            ->and(ProductLink::query()->count())->toBe(1)
            ->and(array_keys($this->network->network($member->id)))->toBe([$canonical->id])
            ->and($this->network->network($solo->id))->toBe([])
            ->and($this->network->compatiblePairs($member->id, $memberM->id))->toEqualCanonicalizing([[$member->id, $memberM->id], [$canonical->id, $canonicalM->id]]);

        // A second run, and a group left in place as history, change nothing.
        expect(app(ConvertSourcingGroupsToProductLinks::class)->handle())->toBe(0)
            ->and(ProductLink::query()->count())->toBe(1)
            ->and(ProductLinkVariantMapping::query()->count())->toBe(1)
            ->and($group->refresh()->products()->active()->count())->toBe(2);
    });
});
