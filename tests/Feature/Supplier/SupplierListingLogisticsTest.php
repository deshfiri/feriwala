<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A Supplier's proposed logistics figures are a proposal only -- staff must
 * confirm or correct the final Central Product/Variant values during
 * approval (beta-critical batch, Commit 1). Approving an item is not, on its
 * own, a logistics decision: the Supplier's proposal never reaches the
 * catalogue unless a reviewer explicitly confirms it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** Staff who may decide a listing *and* create/edit a Central Product. */
function supplierListingLogisticsTestReviewer(): User
{
    $staff = testPlatformStaff(PlatformRole::SupplierManager);
    $staff->assignRole(PlatformRole::ProductManager->value);

    return $staff;
}

it('stores a supplier\'s proposed logistics figures without touching any central record', function () {
    $listing = supplierTestListing(Supplier::factory()->create(), [[
        'proposed_net_weight_grams' => 450,
        'proposed_length_cm' => '30.50',
        'proposed_ships_by_box' => true,
        'proposed_pieces_per_box' => 12,
        'proposed_is_fragile' => true,
    ]]);

    $item = $listing->items()->sole();

    expect($item->proposed_net_weight_grams)->toBe(450)
        ->and($item->proposed_length_cm)->toBe('30.50')
        ->and($item->proposed_ships_by_box)->toBeTrue()
        ->and($item->proposed_pieces_per_box)->toBe(12)
        ->and($item->proposed_is_fragile)->toBeTrue()
        ->and($item->proposedLogistics()['net_weight_grams'])->toBe(450);

    expect(Product::query()->count())->toBe(0);
});

it('does not write any logistics to the central product when a reviewer approves without confirming', function () {
    $listing = supplierTestListing(Supplier::factory()->create(), [[
        'proposed_net_weight_grams' => 450,
    ]]);
    $item = $listing->items()->firstOrFail();
    $product = websiteTestProduct();

    $this->actingAs(supplierListingLogisticsTestReviewer())
        ->post(route('admin.supplier-listings.decision.store', $listing), [
            'reason' => 'Approved as proposed.',
            ...supplierTestConnect($product),
            'items' => [['item_id' => $item->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
        ])->assertSessionHasNoErrors();

    expect($product->refresh()->net_weight_grams)->toBeNull();
});

it('writes a reviewer\'s confirmed logistics figures to the central product on approval', function () {
    $listing = supplierTestListing(Supplier::factory()->create(), [[
        'proposed_net_weight_grams' => 450,
        'proposed_length_cm' => '30.50',
    ]]);
    $item = $listing->items()->firstOrFail();
    $product = websiteTestProduct();

    $this->actingAs(supplierListingLogisticsTestReviewer())
        ->post(route('admin.supplier-listings.decision.store', $listing), [
            'reason' => 'Approved; logistics confirmed as proposed.',
            ...supplierTestConnect($product),
            'items' => [[
                'item_id' => $item->public_id,
                'decision' => 'approve',
                'platform_rate' => '1300.00',
                // Pre-filled from the proposal and submitted unchanged --
                // the ordinary "confirm" case.
                'logistics' => [
                    'net_weight_grams' => 450,
                    'length_cm' => '30.50',
                ],
            ]],
        ])->assertSessionHasNoErrors();

    $product->refresh();
    expect($product->net_weight_grams)->toBe(450)
        ->and($product->length_cm)->toBe('30.50');
});

it('lets a reviewer correct the supplier\'s proposed figure rather than merely confirm it', function () {
    $listing = supplierTestListing(Supplier::factory()->create(), [[
        'proposed_net_weight_grams' => 450,
    ]]);
    $item = $listing->items()->firstOrFail();
    $product = websiteTestProduct();

    $this->actingAs(supplierListingLogisticsTestReviewer())
        ->post(route('admin.supplier-listings.decision.store', $listing), [
            'reason' => 'Supplier\'s proposed weight was wrong; corrected after measuring.',
            ...supplierTestConnect($product),
            'items' => [[
                'item_id' => $item->public_id,
                'decision' => 'approve',
                'platform_rate' => '1300.00',
                'logistics' => ['net_weight_grams' => 600],
            ]],
        ])->assertSessionHasNoErrors();

    // The staff-confirmed figure stands, not the Supplier's own proposal.
    expect($product->refresh()->net_weight_grams)->toBe(600);
});

it('writes confirmed logistics to the connected variant, not the product, when one is given', function () {
    $product = websiteTestProduct();
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => $product->sku.'-M',
        'combination_key' => 'v1',
    ]);

    $listing = supplierTestListing(Supplier::factory()->create(), [[
        'proposed_net_weight_grams' => 300,
    ]]);
    $item = $listing->items()->firstOrFail();

    $this->actingAs(supplierListingLogisticsTestReviewer())
        ->post(route('admin.supplier-listings.decision.store', $listing), [
            'reason' => 'Approved for the M variant.',
            ...supplierTestConnect($product),
            'items' => [[
                'item_id' => $item->public_id,
                'decision' => 'approve',
                'variant_id' => $variant->public_id,
                'platform_rate' => '1300.00',
                'logistics' => ['net_weight_grams' => 300],
            ]],
        ])->assertSessionHasNoErrors();

    expect($variant->refresh()->net_weight_grams)->toBe(300)
        ->and($product->refresh()->net_weight_grams)->toBeNull();
});

it('rejects a zero or negative proposed logistics figure from the supplier', function () {
    supplierTestSignIn(Supplier::factory()->create());

    $this->post(route('supplier.listings.store'), [
        'product_name' => 'Cotton panjabi',
        'items' => [[
            'variant_label' => null,
            'supplier_sku' => 'PJ-900',
            'supplier_rate' => '1000.00',
            'currency_code' => 'BDT',
            'available_quantity' => 10,
            'proposed_net_weight_grams' => 0,
        ]],
    ])->assertInvalid(['items.0.proposed_net_weight_grams']);
});
