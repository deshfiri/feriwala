<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Actions\ManageProductLinks;
use App\Domain\Sourcing\Enums\ProductLinkStatus;
use App\Domain\Sourcing\Models\ProductLink;
use App\Domain\Sourcing\Models\ProductLinkVariantMapping;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
 * The Linked Products section of the Admin Product workspace, and the endpoints
 * behind it: confirmed by staff, one direct connection at a time, never
 * automatic, and out of reach of anybody without the permission.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::ProductManager);
    $this->links = app(ManageProductLinks::class);

    $this->a = websiteTestProduct(['name' => 'Cotton Pants', 'sku' => 'AAAA11111']);
    $this->b = websiteTestProduct(['name' => 'Men Trousers', 'sku' => 'BBBB22222', 'barcode' => '8941100500012']);
    $this->c = websiteTestProduct(['name' => 'Chino Pants', 'sku' => 'CCCC33333']);
});

describe('searching', function () {
    it('finds Products by BPC, title, SKU or barcode, never including the excluded ones, and links nothing', function () {
        foreach ([['q' => 'BBBB22222', 'expect' => $this->b], ['q' => 'trousers', 'expect' => $this->b], ['q' => '8941100500012', 'expect' => $this->b], ['q' => 'cccc3', 'expect' => $this->c]] as $case) {
            $ids = collect($this->actingAs($this->staff)->getJson(route('admin.catalog.product-links.search', ['q' => $case['q']]))
                ->assertOk()->json('data'))->pluck('id')->all();

            expect($ids)->toContain($case['expect']->public_id);
        }

        $excluded = $this->getJson(route('admin.catalog.product-links.search', ['q' => 'pants', 'exclude' => [$this->a->public_id]]))
            ->assertOk()->json('data');

        expect(collect($excluded)->pluck('id')->all())->toBe([$this->c->public_id])
            ->and($excluded[0])->toHaveKeys(['id', 'name', 'bpc', 'sku', 'barcode', 'image_url', 'variant_count', 'variant_labels', 'supplier_sources', 'warehouse_sources'])
            ->and(ProductLink::query()->count())->toBe(0);
    });

    it('returns nothing for a very short search, and carries no price or supplier name', function () {
        $this->actingAs($this->staff)->getJson(route('admin.catalog.product-links.search', ['q' => 'a']))->assertOk()->assertJson(['data' => []]);

        $payload = json_encode($this->getJson(route('admin.catalog.product-links.search', ['q' => 'Cotton']))->json());

        expect($payload)->not->toContain('rate')->not->toContain('price')->not->toContain('business_name');
    });

    it('is closed to staff without catalogue-link access, and to business accounts', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->getJson(route('admin.catalog.product-links.search', ['q' => 'pants']))->assertForbidden();

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->getJson(route('admin.catalog.product-links.search', ['q' => 'pants']))->assertForbidden();
    });
});

describe('linking and unlinking', function () {
    it('links two Products from the workspace, with a reason, in either direction', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.catalog.products.links.store', $this->b->public_id), ['product_id' => $this->a->public_id, 'reason' => 'Same item.'])
            ->assertSessionHasNoErrors();

        $link = ProductLink::query()->firstOrFail();

        expect($link->link_reason)->toBe('Same item.')
            ->and($link->linked_by)->toBe($this->staff->id)
            ->and([$link->product_a_id, $link->product_b_id])->toBe([min($this->a->id, $this->b->id), max($this->a->id, $this->b->id)]);

        // The same pair from the other end is a duplicate, not a second link.
        $this->post(route('admin.catalog.products.links.store', $this->a->public_id), ['product_id' => $this->b->public_id])
            ->assertSessionHasErrors('product_id');

        expect(ProductLink::query()->count())->toBe(1);
    });

    it('refuses a Product linked to itself, or to one that does not exist', function () {
        $this->actingAs($this->staff)
            ->post(route('admin.catalog.products.links.store', $this->a->public_id), ['product_id' => $this->a->public_id])
            ->assertSessionHasErrors('product_id');

        $this->post(route('admin.catalog.products.links.store', $this->a->public_id), ['product_id' => 'nope'])
            ->assertSessionHasErrors('product_id');

        expect(ProductLink::query()->count())->toBe(0);
    });

    it('shows direct and indirect Products, and unlinks only a direct connection', function () {
        // A-B and B-C: C is indirect for A.
        $ab = $this->links->link($this->staff, $this->a, $this->b);
        $bc = $this->links->link($this->staff, $this->b, $this->c);

        $this->actingAs($this->staff)->get(route('admin.catalog.products.edit', $this->a->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('linked_products.current.id', $this->a->public_id)
                ->has('linked_products.direct', 1)
                ->where('linked_products.direct.0.id', $ab->public_id)
                ->where('linked_products.direct.0.product.id', $this->b->public_id)
                ->where('linked_products.direct.0.product.bpc', 'BBBB22222')
                ->has('linked_products.indirect', 1)
                ->where('linked_products.indirect.0.product.id', $this->c->public_id)
                ->where('linked_products.indirect.0.distance', 2)
                ->where('linked_products.can.unlink', true));

        // B-C is not one of A's own connections, so A's page cannot remove it.
        $this->delete(route('admin.catalog.products.links.destroy', [$this->a->public_id, $bc->public_id]))->assertNotFound();
        expect($bc->refresh()->status)->toBe(ProductLinkStatus::Active);

        $this->delete(route('admin.catalog.products.links.destroy', [$this->a->public_id, $ab->public_id]), ['reason' => 'Different item.'])
            ->assertSessionHasNoErrors();

        expect($ab->refresh()->status)->toBe(ProductLinkStatus::Unlinked)
            ->and($ab->unlink_reason)->toBe('Different item.')
            ->and($bc->refresh()->status)->toBe(ProductLinkStatus::Active);
    });

    it('is refused to staff who may only view, and to business accounts', function () {
        $viewer = testPlatformStaff(PlatformRole::Admin);
        $viewer->roles->each(fn ($role) => $role->revokePermissionTo(['product_link.create', 'product_link.edit']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $viewer = $viewer->fresh();

        $link = $this->links->link($this->staff, $this->a, $this->b);

        $this->actingAs($viewer)->get(route('admin.catalog.products.edit', $this->a->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('linked_products.can.link', false)->where('linked_products.can.unlink', false));

        $this->post(route('admin.catalog.products.links.store', $this->a->public_id), ['product_id' => $this->c->public_id])->assertForbidden();
        $this->delete(route('admin.catalog.products.links.destroy', [$this->a->public_id, $link->public_id]))->assertForbidden();

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->post(route('admin.catalog.products.links.store', $this->a->public_id), ['product_id' => $this->c->public_id])->assertForbidden();

        expect(ProductLink::query()->count())->toBe(1)
            ->and($link->refresh()->status)->toBe(ProductLinkStatus::Active);
    });

    it('hides the section from staff who may not view links', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $viewer->roles->each(fn ($role) => $role->revokePermissionTo('product_link.view'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer->fresh())->get(route('admin.catalog.products.edit', $this->a->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('linked_products', null));
    });
});

describe('variation matches', function () {
    it('matches and unmatches a variation pair through the workspace, and refuses a wrong one', function () {
        $va = ProductVariant::create(['product_id' => $this->a->id, 'sku' => 'A-M', 'combination_key' => 'm']);
        $vb = ProductVariant::create(['product_id' => $this->b->id, 'sku' => 'B-M', 'combination_key' => 'm']);
        $link = $this->links->link($this->staff, $this->a, $this->b);

        $this->actingAs($this->staff)
            ->post(route('admin.catalog.products.links.variants.store', [$this->a->public_id, $link->public_id]), [
                'variant' => $vb->public_id, 'other_variant' => $va->public_id,
            ])->assertSessionHasErrors('variant');

        expect(ProductLinkVariantMapping::query()->count())->toBe(0);

        $this->post(route('admin.catalog.products.links.variants.store', [$this->a->public_id, $link->public_id]), [
            'variant' => $va->public_id, 'other_variant' => $vb->public_id,
        ])->assertSessionHasNoErrors();

        $mapping = ProductLinkVariantMapping::query()->active()->firstOrFail();

        $this->get(route('admin.catalog.products.edit', $this->a->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('linked_products.direct.0.variant_matches.0.mine.id', $va->public_id)
                ->where('linked_products.direct.0.variant_matches.0.theirs.id', $vb->public_id));

        $this->delete(route('admin.catalog.products.links.variants.destroy', [$this->a->public_id, $link->public_id, $mapping->public_id]))
            ->assertSessionHasNoErrors();

        expect(ProductLinkVariantMapping::query()->active()->count())->toBe(0);
    });
});
