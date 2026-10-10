<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Supplier\Models\Supplier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The right-hand panel that picks the Product a Supplier listing connects to, or
 * links with: every Product, page by page, for the people who make that choice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->reviewer = testPlatformStaff(PlatformRole::SupplierManager);
    $this->a = websiteTestProduct(['name' => 'Cotton Pants']);
    $this->b = websiteTestProduct(['name' => 'Denim Jacket']);
});

it('lists Products before any search, narrows by a term and pages on', function () {
    $all = collect($this->actingAs($this->reviewer)->getJson(route('admin.catalog.product-links.browse'))->assertOk()->json('data'))->pluck('id')->all();

    expect($all)->toContain($this->a->public_id, $this->b->public_id);

    $found = $this->getJson(route('admin.catalog.product-links.browse', ['q' => 'denim']))->json('data');

    expect(collect($found)->pluck('id')->all())->toBe([$this->b->public_id])
        ->and($this->getJson(route('admin.catalog.product-links.browse', ['page' => 2]))->json())->toMatchArray(['has_more' => false]);
});

it('is closed to staff without a reason to choose Products, and to business accounts', function () {
    $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
        ->getJson(route('admin.catalog.product-links.browse'))->assertForbidden();

    $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
        ->getJson(route('admin.catalog.product-links.browse'))->assertForbidden();
});

it('says the connected Product back on the review page so it is never asked again', function () {
    $listing = supplierTestListing(Supplier::factory()->create());
    $listing->forceFill(['connected_product_id' => $this->a->id])->save();

    $this->actingAs($this->reviewer)->get(route('admin.supplier-listings.show', $listing))
        ->assertInertia(fn (Assert $page) => $page
            ->where('listing.connected_product', $this->a->public_id)
            ->where('listing.connected_product_summary.id', $this->a->public_id)
            ->where('listing.connected_product_summary.name', 'Cotton Pants'));

    $fresh = supplierTestListing(Supplier::factory()->create());

    $this->get(route('admin.supplier-listings.show', $fresh))
        ->assertInertia(fn (Assert $page) => $page->where('listing.connected_product_summary', null));
});
