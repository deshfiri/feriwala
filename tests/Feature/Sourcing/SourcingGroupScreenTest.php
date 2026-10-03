<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Sourcing\Actions\ManageSourcingGroups;
use App\Domain\Sourcing\Models\ProductSourcingGroup;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The Sourcing Groups admin screens: who reaches them, what they show, and
 * that every change flows through the audited action.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
});

function sourcingScreenGroup(string $code = 'regular-pants', string $name = 'Regular pants'): ProductSourcingGroup
{
    return app(ManageSourcingGroups::class)->create(
        test()->staff,
        ['code' => $code, 'name_en' => $name, 'name_bn' => 'রেগুলার প্যান্ট'],
    );
}

it('lists groups with counts, search and status filter', function () {
    $pants = sourcingScreenGroup('regular-pants', 'Regular pants');
    $shirts = sourcingScreenGroup('polo-shirts', 'Polo shirts');
    app(ManageSourcingGroups::class)->addProduct($this->staff, $pants, websiteTestProduct(), 'Reference.');
    app(ManageSourcingGroups::class)->setActive($this->staff, $shirts, false, 'Paused.');

    $this->actingAs($this->staff)->get(route('admin.sourcing-groups.index'))
        ->assertInertia(fn (Assert $page) => $page->component('admin/sourcing-groups/index')
            ->has('groups.data', 2)
            ->where('can.create', true));

    $this->get(route('admin.sourcing-groups.index', ['search' => 'polo']))
        ->assertInertia(fn (Assert $page) => $page->has('groups.data', 1)->where('groups.data.0.code', 'polo-shirts'));

    $this->get(route('admin.sourcing-groups.index', ['status' => 'active']))
        ->assertInertia(fn (Assert $page) => $page->has('groups.data', 1)
            ->where('groups.data.0.code', 'regular-pants')
            ->where('groups.data.0.products_count', 1));
});

it('creates a group and refuses a duplicate or malformed code', function () {
    $payload = ['code' => 'regular-pants', 'name_en' => 'Regular pants', 'name_bn' => 'রেগুলার প্যান্ট'];

    $response = $this->actingAs($this->staff)->post(route('admin.sourcing-groups.store'), $payload);

    $group = ProductSourcingGroup::query()->where('code', 'regular-pants')->firstOrFail();
    $response->assertRedirect(route('admin.sourcing-groups.show', $group));

    $this->post(route('admin.sourcing-groups.store'), $payload)->assertSessionHasErrors('code');
    $this->post(route('admin.sourcing-groups.store'), [...$payload, 'code' => 'Bad Code'])->assertSessionHasErrors('code');
    $this->post(route('admin.sourcing-groups.store'), [...$payload, 'code' => 'x', 'name_bn' => ''])->assertSessionHasErrors('name_bn');
});

it('shows members, mappings, sources and history, and finds ungrouped products to add', function () {
    $group = sourcingScreenGroup();
    $manage = app(ManageSourcingGroups::class);
    $canonical = websiteTestProduct(['name' => 'Regular Pants']);
    $member = websiteTestProduct(['name' => 'Cotton Pants']);
    $manage->addProduct($this->staff, $group, $canonical, 'Reference.');
    $manage->addProduct($this->staff, $group, $member, 'Equivalent.');
    $offer = supplierTestOffer(null, $member);
    $stranger = websiteTestProduct(['name' => 'Cotton Trousers']);

    $this->actingAs($this->staff)->get(route('admin.sourcing-groups.show', ['group' => $group, 'product_search' => 'trousers']))
        ->assertInertia(fn (Assert $page) => $page->component('admin/sourcing-groups/show')
            ->where('group.code', 'regular-pants')
            ->has('products', 2)
            ->where('products.0.is_canonical', true)
            ->has('offers', 1)
            ->where('offers.0.id', $offer->public_id)
            ->has('history', 3)
            ->has('product_matches', 1)
            ->where('product_matches.0.id', $stranger->public_id)
            ->missing('offers.0.supplier_rate'));
});

it('adds and removes a product through the screen with a mandatory reason', function () {
    $group = sourcingScreenGroup();
    $product = websiteTestProduct();

    $this->actingAs($this->staff)
        ->post(route('admin.sourcing-groups.products.store', $group), ['product_id' => $product->public_id])
        ->assertSessionHasErrors('reason');

    $this->post(route('admin.sourcing-groups.products.store', $group), ['product_id' => $product->public_id, 'reason' => 'Reference product.'])
        ->assertSessionHasNoErrors();

    expect($group->products()->active()->count())->toBe(1);

    $this->delete(route('admin.sourcing-groups.products.destroy', [$group, $product]), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    $this->delete(route('admin.sourcing-groups.products.destroy', [$group, $product]), ['reason' => 'Not equivalent.'])
        ->assertSessionHasNoErrors();

    expect($group->products()->active()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'sourcing_group.product_removed')->value('reason'))->toBe('Not equivalent.');
});

it('reports a refused change as a form error in plain words', function () {
    $first = sourcingScreenGroup('first');
    $second = sourcingScreenGroup('second', 'Second');
    $product = websiteTestProduct();
    app(ManageSourcingGroups::class)->addProduct($this->staff, $first, $product, 'Reference.');

    $this->actingAs($this->staff)
        ->post(route('admin.sourcing-groups.products.store', $second), ['product_id' => $product->public_id, 'reason' => 'Also here.'])
        ->assertSessionHasErrors('group');

    expect(session('errors')->first('group'))->toContain('already in the sourcing group');
});

it('maps and unmaps a variation through the screen', function () {
    $group = sourcingScreenGroup();
    $manage = app(ManageSourcingGroups::class);
    $canonical = websiteTestProduct();
    $member = websiteTestProduct();
    $manage->addProduct($this->staff, $group, $canonical, 'Reference.');
    $manage->addProduct($this->staff, $group, $member, 'Equivalent.');
    $canonicalM = ProductVariant::create(['product_id' => $canonical->id, 'sku' => 'RP-M', 'combination_key' => 'm']);
    $memberM = ProductVariant::create(['product_id' => $member->id, 'sku' => 'CP-M', 'combination_key' => 'm']);

    $this->actingAs($this->staff)->post(route('admin.sourcing-groups.mappings.store', $group), [
        'product_id' => $member->public_id,
        'variant_id' => $memberM->public_id,
        'canonical_variant_id' => $canonicalM->public_id,
        'reason' => 'Both size M.',
    ])->assertSessionHasNoErrors();

    $mapping = $group->variantMappings()->active()->firstOrFail();

    $this->get(route('admin.sourcing-groups.show', $group))
        ->assertInertia(fn (Assert $page) => $page->has('mappings', 1)->where('mappings.0.variant', 'CP-M')
            ->where('mappings.0.canonical_variant', 'RP-M'));

    $this->delete(route('admin.sourcing-groups.mappings.destroy', [$group, $mapping]), ['reason' => 'Wrong.'])
        ->assertSessionHasNoErrors();

    expect($group->variantMappings()->active()->count())->toBe(0);
});

it('deactivates a group only with a reason', function () {
    $group = sourcingScreenGroup();

    $this->actingAs($this->staff)->post(route('admin.sourcing-groups.toggle', $group), ['is_active' => 0])
        ->assertSessionHasErrors('reason');

    $this->post(route('admin.sourcing-groups.toggle', $group), ['is_active' => 0, 'reason' => 'Retired.'])
        ->assertSessionHasNoErrors();

    expect($group->fresh()->is_active)->toBeFalse();
});

describe('access', function () {
    it('lets each role see only what its permissions allow', function (PlatformRole $role, int $index, int $create, int $toggle) {
        $group = sourcingScreenGroup();
        $user = testPlatformStaff($role);

        $this->actingAs($user)->get(route('admin.sourcing-groups.index'))->assertStatus($index);
        $this->post(route('admin.sourcing-groups.store'), ['code' => 'new-one', 'name_en' => 'N', 'name_bn' => 'ন'])
            ->assertStatus($create);
        $this->post(route('admin.sourcing-groups.toggle', $group), ['is_active' => 0, 'reason' => 'x'])
            ->assertStatus($toggle);
    })->with([
        'admin: view only' => [PlatformRole::Admin, 200, 403, 403],
        'product manager: full' => [PlatformRole::ProductManager, 200, 302, 302],
        'supplier manager: no toggle' => [PlatformRole::SupplierManager, 200, 302, 403],
        'order manager: nothing' => [PlatformRole::OrderManager, 403, 403, 403],
        'report viewer: nothing' => [PlatformRole::ReportViewer, 403, 403, 403],
    ]);

    it('shares the navigation key only with roles that may open the screen', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))->get(route('admin.sourcing-groups.index'))
            ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['sourcing_group.view'] === true));

        $this->actingAs(testPlatformStaff(PlatformRole::OrderManager))->get(route('admin.orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('permissions', fn ($permissions) => $permissions['sourcing_group.view'] === false));
    });

    it('keeps a Supplier session out entirely', function () {
        $group = sourcingScreenGroup();
        $supplier = Supplier::factory()->create();

        $this->actingAs($supplier, 'supplier')->get(route('admin.sourcing-groups.index'))->assertRedirect(route('login'));
        $this->get(route('admin.sourcing-groups.show', $group))->assertRedirect(route('login'));
    });

    it('keeps a Client/Partner session out', function () {
        $group = sourcingScreenGroup();
        $owner = testBusinessAccount()->owner;

        $this->actingAs($owner)->get(route('admin.sourcing-groups.index'))->assertForbidden();
        $this->get(route('admin.sourcing-groups.show', $group))->assertForbidden();
    });
});
