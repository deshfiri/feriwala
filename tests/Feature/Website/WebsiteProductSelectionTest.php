<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteProductPriceRule;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Choosing what a storefront sells, pricing it, and putting it on sale
 * (§15, §15.1, §8.1, P5-1–P5-7).
 *
 * The three things a browser must never decide: whether the account may sell
 * this product at all, what it may charge, and how many it may have on sale.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::ProductPublishLimit->value => '2',
        PackageFeature::DropshippingEnabled->value => '1',
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->product = websiteTestProduct();
});

describe('choosing a product', function () {
    it('selects it unpublished, at the price Feriwala suggests', function () {
        $this->actingAs($this->account->owner)
            ->post(route('websites.products.store', $this->website->public_id), [
                'product' => $this->product->public_id,
            ])
            ->assertRedirect();

        $selection = WebsiteProduct::query()->firstOrFail();

        expect($selection->status)->toBe(WebsiteProductStatus::Selected)
            ->and($selection->sync_status)->toBe(WebsiteSyncStatus::Pending)
            ->and($selection->price_minor?->minorUnits)->toBe(250000)
            ->and($selection->business_account_id)->toBe($this->account->id);
    });

    it('is the same choice twice', function () {
        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->account->owner)
                ->from(route('websites.products.index', $this->website->public_id))
                ->post(route('websites.products.store', $this->website->public_id), [
                    'product' => $this->product->public_id,
                ]);
        }

        expect(WebsiteProduct::query()->count())->toBe(1);
    });

    it('refuses a product the account may not sell', function () {
        $withdrawn = websiteTestProduct(['dropshipping_status' => ProductStatus::DropshippingDisabled]);

        $this->actingAs($this->account->owner)
            ->post(route('websites.products.store', $this->website->public_id), [
                'product' => $withdrawn->public_id,
            ])
            ->assertNotFound();

        expect(WebsiteProduct::query()->count())->toBe(0);
    });

    it('never reaches another account\'s website', function () {
        $others = websiteTestAccount();
        $theirs = Website::factory()->forAccount($others)->active()->create();

        $this->actingAs($this->account->owner)
            ->post(route('websites.products.store', $theirs->public_id), [
                'product' => $this->product->public_id,
            ])
            ->assertNotFound();
    });
});

describe('pricing it', function () {
    beforeEach(function () {
        $this->selection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $this->product->id,
            'status' => WebsiteProductStatus::Selected,
            'sync_status' => WebsiteSyncStatus::Synced,
            'currency_code' => 'BDT',
            'price_minor' => 250000,
        ]);
    });

    it('keeps a price inside the product\'s own selling bounds', function () {
        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 100000,
            ])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 500000,
            ])
            ->assertSessionHasErrors('price');

        expect($this->selection->refresh()->price_minor->minorUnits)->toBe(250000);
    });

    it('honours the administrator\'s rule over the product\'s bounds', function () {
        WebsiteProductPriceRule::create([
            'product_id' => $this->product->id,
            'allows_user_pricing' => true,
            'currency_code' => 'BDT',
            'min_price_minor' => 300000,
            'max_price_minor' => 320000,
            'locked_fields' => [],
            'effective_from' => now()->subDay(),
        ]);

        // 250,000 was fine under the product's own floor of 200,000; the rule
        // moves the floor to 300,000 and this is now below it.
        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 250000,
            ])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->account->owner)
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 310000,
            ])
            ->assertRedirect();

        expect($this->selection->refresh()->price_minor->minorUnits)->toBe(310000);
    });

    it('caps a price by the allowed margin', function () {
        WebsiteProductPriceRule::create([
            'product_id' => $this->product->id,
            'allows_user_pricing' => true,
            'currency_code' => 'BDT',
            'min_price_minor' => 200000,
            // 20% above the floor: 240,000.
            'max_margin_percent' => 20,
            'locked_fields' => [],
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 260000,
            ])
            ->assertSessionHasErrors('price');

        $this->actingAs($this->account->owner)
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 240000,
            ])
            ->assertRedirect();

        expect($this->selection->refresh()->price_minor->minorUnits)->toBe(240000);
    });

    it('refuses any price at all where Feriwala sets it', function () {
        WebsiteProductPriceRule::create([
            'product_id' => $this->product->id,
            'allows_user_pricing' => false,
            'currency_code' => 'BDT',
            'locked_fields' => [],
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'price' => 250000,
            ])
            ->assertSessionHasErrors('price');
    });

    it('refuses a locked field rather than quietly dropping it', function () {
        WebsiteProductPriceRule::create([
            'product_id' => $this->product->id,
            'allows_user_pricing' => true,
            'currency_code' => 'BDT',
            'locked_fields' => ['promo_title'],
            'effective_from' => now()->subDay(),
        ]);

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'promo_title' => 'Eid offer',
            ])
            ->assertSessionHasErrors('promo_title');

        expect($this->selection->refresh()->promo_title)->toBeNull();
    });

    it('refuses a promotion that is not a reduction', function () {
        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'promotional_price' => 300000,
            ])
            ->assertSessionHasErrors('promotional_price');
    });

    it('places it in one of the shop\'s own categories, and nobody else\'s', function () {
        $mine = WebsiteCategory::create([
            'website_id' => $this->website->id,
            'name' => 'Eid collection',
            'slug' => 'eid-collection',
            'position' => 1,
        ]);

        $others = WebsiteCategory::create([
            'website_id' => Website::factory()->create()->id,
            'name' => 'Somebody else',
            'slug' => 'somebody-else',
            'position' => 1,
        ]);

        $this->actingAs($this->account->owner)
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'website_category_id' => $mine->public_id,
                'display_order' => 3,
                'is_featured' => true,
            ])
            ->assertRedirect();

        $this->selection->refresh();

        expect($this->selection->website_category_id)->toBe($mine->id)
            ->and($this->selection->display_order)->toBe(3)
            ->and($this->selection->is_featured)->toBeTrue()
            // Any change means the storefront's copy is behind (§17.2, P5-7).
            ->and($this->selection->sync_status)->toBe(WebsiteSyncStatus::Pending);

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->patch(route('websites.products.update', [$this->website->public_id, $this->selection->public_id]), [
                'website_category_id' => $others->public_id,
            ])
            ->assertSessionHasErrors('website_category_id');
    });
});

describe('putting it on sale', function () {
    beforeEach(function () {
        $this->selection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $this->product->id,
            'status' => WebsiteProductStatus::Selected,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price_minor' => 250000,
        ]);
    });

    it('publishes and unpublishes, keeping both dates', function () {
        $this->actingAs($this->account->owner)
            ->put(route('websites.products.publication.update', [$this->website->public_id, $this->selection->public_id]), [
                'published' => true,
            ])
            ->assertRedirect();

        expect($this->selection->refresh()->status)->toBe(WebsiteProductStatus::Published)
            ->and($this->selection->published_at)->not->toBeNull();

        $this->actingAs($this->account->owner)
            ->put(route('websites.products.publication.update', [$this->website->public_id, $this->selection->public_id]), [
                'published' => false,
            ]);

        $this->selection->refresh();

        expect($this->selection->status)->toBe(WebsiteProductStatus::Unpublished)
            ->and($this->selection->published_at)->not->toBeNull()
            ->and($this->selection->unpublished_at)->not->toBeNull();
    });

    it('refuses to publish without a price', function () {
        $this->selection->forceFill(['price_minor' => null])->save();

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->put(route('websites.products.publication.update', [$this->website->public_id, $this->selection->public_id]), [
                'published' => true,
            ])
            ->assertSessionHasErrors('price');

        expect($this->selection->refresh()->status)->toBe(WebsiteProductStatus::Selected);
    });

    it('holds the account to its package publish limit, across every storefront', function () {
        $second = Website::factory()->forAccount($this->account)->active()->create();

        // Two published already: the package allows two.
        foreach ([$this->website, $second] as $index => $site) {
            WebsiteProduct::create([
                'website_id' => $site->id,
                'business_account_id' => $this->account->id,
                'product_id' => websiteTestProduct()->id,
                'status' => WebsiteProductStatus::Published,
                'sync_status' => WebsiteSyncStatus::Synced,
                'currency_code' => 'BDT',
                'price_minor' => 250000,
                'published_at' => now()->subDays($index + 1),
            ]);
        }

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->put(route('websites.products.publication.update', [$this->website->public_id, $this->selection->public_id]), [
                'published' => true,
            ])
            ->assertSessionHasErrors('product');

        expect($this->selection->refresh()->status)->toBe(WebsiteProductStatus::Selected);
    });

    it('refuses to publish a product that has since been withdrawn', function () {
        $this->product->forceFill(['dropshipping_status' => ProductStatus::DropshippingDisabled])->save();

        $this->actingAs($this->account->owner)
            ->from(route('websites.products.index', $this->website->public_id))
            ->put(route('websites.products.publication.update', [$this->website->public_id, $this->selection->public_id]), [
                'published' => true,
            ])
            ->assertSessionHasErrors('product');
    });

    it('removes the selection without touching the catalogue product', function () {
        $this->actingAs($this->account->owner)
            ->delete(route('websites.products.destroy', [$this->website->public_id, $this->selection->public_id]))
            ->assertRedirect();

        expect(WebsiteProduct::query()->count())->toBe(0)
            ->and(Product::query()->whereKey($this->product->id)->exists())->toBeTrue();
    });
});

describe('the administrator\'s bounds', function () {
    beforeEach(function () {
        $this->seed(RolesAndPermissionsSeeder::class);
    });

    it('opens a rule and closes it rather than editing it', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)
            ->post(route('admin.website-pricing.store'), [
                'product' => $this->product->public_id,
                'allows_user_pricing' => true,
                'min_price_minor' => 210000,
                'max_price_minor' => 390000,
                'max_margin_percent' => 25,
                'locked_fields' => ['promo_title'],
                'effective_from' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect(route('admin.website-pricing.index'));

        $rule = WebsiteProductPriceRule::query()->firstOrFail();

        expect($rule->product_id)->toBe($this->product->id)
            ->and($rule->locked_fields)->toBe(['promo_title'])
            ->and($rule->effective_to)->toBeNull();

        $this->actingAs($staff)
            ->delete(route('admin.website-pricing.close', $rule->public_id))
            ->assertRedirect();

        expect($rule->refresh()->effective_to)->not->toBeNull();
    });

    it('refuses a field nobody may lock', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->from(route('admin.website-pricing.index'))
            ->post(route('admin.website-pricing.store'), [
                'allows_user_pricing' => true,
                'locked_fields' => ['wholesale_price'],
                'effective_from' => now()->toDateString(),
            ])
            ->assertSessionHasErrors('locked_fields.0');
    });

    it('is refused to a partner, and offered in the navigation only with the permission', function () {
        $this->actingAs($this->account->owner)
            ->get(route('admin.website-pricing.index'))
            ->assertForbidden();

        $page = $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->get(route('admin.website-pricing.index'));

        $page->assertOk();

        expect($page->viewData('page')['props']['permissions']['website.manage_settings'] ?? null)->toBeTrue();
    });
});

describe('the dropshipping catalogue', function () {
    it('offers the partner\'s open storefronts on a product, saying which already sell it', function () {
        $other = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Second shop']);

        WebsiteProduct::create([
            'website_id' => $other->id,
            'business_account_id' => $this->account->id,
            'product_id' => $this->product->id,
            'status' => WebsiteProductStatus::Selected,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
        ]);

        $page = $this->actingAs($this->account->owner)
            ->get(route('catalog.dropshipping.show', $this->product->slug));

        $page->assertOk();

        $websites = collect($page->viewData('page')['props']['websites'])->keyBy('id');

        expect($websites)->toHaveCount(2)
            ->and($websites[$other->public_id]['selected'])->toBeTrue()
            ->and($websites[$this->website->public_id]['selected'])->toBeFalse();

        // The wholesale screen is a different act and offers no storefront.
        $wholesale = $this->actingAs($this->account->owner)
            ->get(route('catalog.wholesale.show', $this->product->slug));

        $wholesale->assertOk();

        expect($wholesale->viewData('page')['props']['websites'])->toBe([]);
    });
});

describe('the shop\'s own arrangement', function () {
    it('adds, hides and removes a category without losing the products in it', function () {
        $this->actingAs($this->account->owner)
            ->post(route('websites.categories.store', $this->website->public_id), [
                'name' => 'Eid collection',
            ])
            ->assertRedirect();

        $category = WebsiteCategory::query()->firstOrFail();

        $selection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $this->product->id,
            'website_category_id' => $category->id,
            'status' => WebsiteProductStatus::Selected,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price_minor' => 250000,
        ]);

        $this->actingAs($this->account->owner)
            ->patch(route('websites.categories.update', [$this->website->public_id, $category->public_id]), [
                'is_active' => false,
            ]);

        expect($category->refresh()->is_active)->toBeFalse();

        $this->actingAs($this->account->owner)
            ->delete(route('websites.categories.destroy', [$this->website->public_id, $category->public_id]))
            ->assertRedirect();

        expect(WebsiteCategory::query()->count())->toBe(0)
            ->and($selection->refresh()->website_category_id)->toBeNull();
    });

    it('is reachable from the website page and lists what the shop sells', function () {
        $this->actingAs($this->account->owner)
            ->get(route('websites.products.index', $this->website->public_id))
            ->assertOk();

        $this->actingAs($this->account->owner)
            ->get(route('websites.categories.index', $this->website->public_id))
            ->assertOk();
    });
});
