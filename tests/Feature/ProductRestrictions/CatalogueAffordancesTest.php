<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * No create or import affordance for anybody who may not author (P3-16, §12).
 *
 * The browser renders what the server's abilities say, so the assertions are
 * on those abilities and on what a refused person is shown: a partner reaching
 * a catalogue address gets the catalogue's own refusal page in the application
 * shell, a member of staff with view-only access gets the screens with nothing
 * to author on them, and an API caller gets JSON rather than a page.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->category = Category::create(['name' => 'Kitchen']);
    $this->product = Product::create(['name' => 'Rice cooker', 'sku' => 'FW-RC', 'category_id' => $this->category->id]);
});

describe('a refused person is shown the catalogue refusal page', function () {
    it('shows a partner the partner refusal at every catalogue administration address', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        foreach ([
            route('admin.catalog.products.index'),
            route('admin.catalog.products.create'),
            route('admin.catalog.products.edit', $this->product->public_id),
            route('admin.catalog.categories.index'),
            route('admin.catalog.brands.index'),
            route('admin.catalog.attributes.index'),
        ] as $url) {
            $this->actingAs($owner)
                ->get($url)
                ->assertForbidden()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('catalog/forbidden')
                    ->where('audience', 'business'),
                );
        }
    });

    it('shows the same page for a crafted write, and changes nothing', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)
            ->post(route('admin.catalog.products.store'), ['name' => 'Mine', 'sku' => 'MINE-1'])
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('catalog/forbidden'));

        expect(Product::query()->where('sku', 'MINE-1')->exists())->toBeFalse();
    });

    it('tells staff whose role lacks the catalogue that it is their role, not their business', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.catalog.products.index'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catalog/forbidden')
                ->where('audience', 'staff'),
            );
    });

    it('tells staff who may view the catalogue that changing it is what their role lacks', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);

        foreach ([
            route('admin.catalog.products.create'),
            route('admin.catalog.products.store'),
        ] as $index => $url) {
            ($index === 0 ? $this->actingAs($viewer)->get($url) : $this->actingAs($viewer)->post($url, ['name' => 'Mine']))
                ->assertForbidden()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('catalog/forbidden')
                    ->where('audience', 'viewer'),
                );
        }
    });

    it('answers an API caller with JSON, never a page', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->getJson(route('admin.catalog.products.index'))
            ->assertForbidden()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('component');
    });

    it('leaves refusals outside the catalogue as they were', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.kyc.index'))
            ->assertForbidden()
            ->assertDontSee('catalog/forbidden');
    });
});

describe('staff who may only view see nothing to author', function () {
    beforeEach(function () {
        $this->viewer = testPlatformStaff(PlatformRole::InventoryManager);
    });

    it('offers no create, bulk or authoring ability on the product screens', function () {
        $this->actingAs($this->viewer)
            ->get(route('admin.catalog.products.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('can.edit', false)
                ->where('can.delete', false)
                ->where('bulk.transitions', [])
                ->where('bulk.enable_channels', false)
                ->where('bulk.disable_channels', false)
                ->where('bulk.feature', false)
                ->where('bulk.assign', false),
            );

        $this->actingAs($this->viewer)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('can.edit', false)
                ->where('can.publish', false)
                ->where('transitions', []),
            );
    });

    it('offers no create ability on categories, brands or attributes, and still shows them', function () {
        foreach (['categories', 'brands', 'attributes'] as $screen) {
            $this->actingAs($this->viewer)
                ->get(route("admin.catalog.{$screen}.index"))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component("admin/catalog/{$screen}")
                    ->where('can.create', false)
                    ->where('can.edit', false)
                    ->where('can.delete', false),
                );
        }
    });
});

describe('a partner catalogue page carries nothing to author', function () {
    it('holds no authoring abilities, and the shared contract grants no catalogue administration', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        foreach (['catalog.wholesale.index', 'catalog.dropshipping.index'] as $screen) {
            $this->actingAs($owner)
                ->get(route($screen))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('catalog/browse')
                    ->missing('can')
                    ->missing('bulk')
                    ->where('permissions', fn ($permissions) => collect($permissions)
                        ->filter(fn (bool $granted, string $name) => str_starts_with($name, 'catalog.') && $granted)
                        ->isEmpty()),
                );
        }
    });
});
