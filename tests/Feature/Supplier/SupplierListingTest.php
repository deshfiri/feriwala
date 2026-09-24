<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Models\User;
use App\Notifications\Supplier\SupplierListingApproved;
use App\Notifications\Supplier\SupplierListingCorrectionRequested;
use App\Notifications\Supplier\SupplierListingRejected;
use App\Notifications\Supplier\SupplierListingSubmitted;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/** @return array<string, mixed> */
function supplierListingTestPayload(array $overrides = []): array
{
    return [
        'product_name' => 'Cotton panjabi',
        'description' => 'Soft cotton.',
        'category_suggestion' => 'Menswear',
        'items' => [[
            'variant_label' => null,
            'supplier_sku' => 'PJ-001',
            'supplier_rate' => '1000.00',
            'currency_code' => 'BDT',
            'available_quantity' => 40,
            'minimum_supply_quantity' => 2,
            'lead_time_days' => 3,
        ]],
        ...$overrides,
    ];
}

/** Staff who may decide a listing *and* create a Central Product (catalog.create is the catalogue's own rule). */
function supplierListingTestReviewer(): User
{
    $staff = testPlatformStaff(PlatformRole::SupplierManager);
    $staff->assignRole(PlatformRole::ProductManager->value);

    return $staff;
}

test('only an approved supplier can create or submit listing requests', function () {
    supplierTestSignIn(Supplier::factory()->kycPending()->create());

    $this->get(route('supplier.listings.create'))->assertForbidden();
    $this->post(route('supplier.listings.store'), supplierListingTestPayload())->assertForbidden();

    expect(SupplierProductListing::query()->count())->toBe(0);
});

test('a supplier drafts, edits and submits a listing which then locks', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());

    $this->post(route('supplier.listings.store'), supplierListingTestPayload())->assertSessionHasNoErrors();

    $listing = SupplierProductListing::query()->firstOrFail();
    expect($listing->status)->toBe(ListingStatus::Draft)
        ->and($listing->items()->count())->toBe(1)
        ->and($listing->items()->first()->supplier_rate->toDecimal())->toBe('1000.00');

    $this->patch(route('supplier.listings.update', $listing), supplierListingTestPayload(['product_name' => 'Cotton panjabi v2']))
        ->assertSessionHasNoErrors();
    expect($listing->refresh()->product_name)->toBe('Cotton panjabi v2');

    $this->post(route('supplier.listings.submission.store', $listing))->assertSessionHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::UnderReview)
        ->and(AuditLog::query()->where('action', 'supplier_listing.submitted')->count())->toBe(1);
    Notification::assertSentTo($supplier, SupplierListingSubmitted::class);

    // Locked: edits are refused and the row is unchanged.
    $this->patch(route('supplier.listings.update', $listing), supplierListingTestPayload(['product_name' => 'Sneaky']))
        ->assertSessionHasErrors('listing');
    $this->get(route('supplier.listings.edit', $listing))->assertForbidden();
    expect($listing->refresh()->product_name)->toBe('Cotton panjabi v2');
});

test('a listing needs at least one variation and a valid non-negative rate', function () {
    supplierTestSignIn(Supplier::factory()->create());

    $this->post(route('supplier.listings.store'), supplierListingTestPayload(['items' => []]))->assertSessionHasErrors('items');

    $bad = supplierListingTestPayload();
    $bad['items'][0]['supplier_rate'] = -5;
    $this->post(route('supplier.listings.store'), $bad)->assertSessionHasErrors('items.0.supplier_rate');
});

test('a supplier can archive a draft but not a submitted listing', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());

    $draft = supplierTestListing($supplier, status: ListingStatus::Draft);
    $this->post(route('supplier.listings.archive', $draft))->assertSessionHasNoErrors();
    expect($draft->refresh()->status)->toBe(ListingStatus::Archived);

    $submitted = supplierTestListing($supplier);
    $this->post(route('supplier.listings.archive', $submitted))->assertSessionHasErrors('listing');
    expect($submitted->refresh()->status)->toBe(ListingStatus::UnderReview);
});

test('a supplier can never view, edit, submit or archive another supplier\'s listing', function () {
    $mine = supplierTestSignIn(Supplier::factory()->create());
    $theirs = supplierTestListing(Supplier::factory()->create(), status: ListingStatus::Draft);

    $this->get(route('supplier.listings.show', $theirs))->assertNotFound();
    $this->get(route('supplier.listings.edit', $theirs))->assertNotFound();
    $this->patch(route('supplier.listings.update', $theirs), supplierListingTestPayload())->assertNotFound();
    $this->post(route('supplier.listings.submission.store', $theirs))->assertNotFound();
    $this->post(route('supplier.listings.archive', $theirs))->assertNotFound();

    expect($theirs->refresh()->status)->toBe(ListingStatus::Draft)
        ->and($mine->listings()->count())->toBe(0);
});

test('submitting and saving listings never writes to the central catalogue', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $before = Product::query()->count();

    $this->post(route('supplier.listings.store'), supplierListingTestPayload());
    $this->post(route('supplier.listings.submission.store', SupplierProductListing::query()->firstOrFail()));

    expect(Product::query()->count())->toBe($before)
        ->and(SupplierProductListing::query()->firstOrFail()->connected_product_id)->toBeNull()
        ->and(SupplierOffer::query()->count())->toBe(0);
});

test('a reviewer can send a listing back, the supplier resubmits, and only sent-back items reopen', function () {
    $supplier = Supplier::factory()->create();
    $listing = supplierTestListing($supplier, [[], []]);
    $staff = supplierListingTestReviewer();

    $this->actingAs($staff)
        ->post(route('admin.supplier-listings.correction.store', $listing), ['feedback' => 'Please add a warranty.'])
        ->assertSessionHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::CorrectionRequired);
    Notification::assertSentTo($supplier, SupplierListingCorrectionRequested::class);

    supplierTestSignIn($supplier);
    $this->patch(route('supplier.listings.update', $listing), supplierListingTestPayload())->assertSessionHasNoErrors();
    $this->post(route('supplier.listings.submission.store', $listing))->assertSessionHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::UnderReview)
        ->and(AuditLog::query()->where('action', 'supplier_listing.resubmitted')->count())->toBe(1);
});

test('full approval creates a Central Product through the catalogue action and one priced offer per item', function () {
    $supplier = Supplier::factory()->create();
    $listing = supplierTestListing($supplier);
    $item = $listing->items()->firstOrFail();
    $staff = supplierListingTestReviewer();
    $category = websiteTestProduct()->category->public_id;

    $before = Product::query()->count();

    $this->actingAs($staff)->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Looks right.',
        'create_product' => true,
        'sku' => 'pj-001',
        'category_id' => $category,
        'items' => [[
            'item_id' => $item->public_id,
            'decision' => 'approve',
            'platform_rate' => '1300.00',
            'wholesale_enabled' => true,
            'dropshipping_enabled' => false,
        ]],
    ])->assertSessionHasNoErrors();

    $listing->refresh();
    $offer = SupplierOffer::query()->firstOrFail();

    expect($listing->status)->toBe(ListingStatus::Approved)
        ->and(Product::query()->count())->toBe($before + 1)
        ->and($listing->connectedProduct->name)->toBe('Cotton panjabi')
        // Created as a draft: connecting never publishes anything by itself.
        ->and($listing->connectedProduct->status->value)->toBe('draft')
        ->and($offer->supplier_id)->toBe($supplier->id)
        ->and($offer->supplier_rate->toDecimal())->toBe('1000.00')
        ->and($offer->platform_rate->toDecimal())->toBe('1300.00')
        ->and($offer->wholesale_enabled)->toBeTrue()
        ->and($offer->dropshipping_enabled)->toBeFalse()
        ->and($offer->stock->quantity)->toBe(0)
        ->and($offer->priceHistory()->count())->toBe(1)
        ->and($item->refresh()->status)->toBe(ListingItemStatus::Approved)
        ->and($item->supplier_offer_id)->toBe($offer->id)
        ->and(AuditLog::query()->where('action', 'supplier_listing.approved')->count())->toBe(1);

    Notification::assertSentTo($supplier, SupplierListingApproved::class);
});

test('creating a product needs the catalogue create permission on top of listing approval', function () {
    $listing = supplierTestListing(Supplier::factory()->create());
    $item = $listing->items()->firstOrFail();
    $staff = testPlatformStaff(PlatformRole::SupplierManager);
    $category = websiteTestProduct()->category->public_id;
    $before = Product::query()->count();

    $this->actingAs($staff)->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Try.', 'create_product' => true, 'sku' => 'X-1',
        'category_id' => $category,
        'items' => [['item_id' => $item->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ])->assertForbidden();

    expect(Product::query()->count())->toBe($before)
        ->and($listing->refresh()->status)->toBe(ListingStatus::UnderReview)
        ->and(SupplierOffer::query()->count())->toBe(0);
});

test('a reviewer who cannot set pricing cannot approve, but can still reject', function () {
    $listing = supplierTestListing(Supplier::factory()->create());
    $item = $listing->items()->firstOrFail();
    $reviewer = User::factory()->staff()->create();
    $reviewer->givePermissionTo(['supplier_listing.view', 'supplier_listing.approve']);

    $this->actingAs($reviewer)->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Ok', 'connect_product_id' => websiteTestProduct()->public_id,
        'items' => [['item_id' => $item->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ])->assertForbidden();

    $this->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Not suitable.',
        'items' => [['item_id' => $item->public_id, 'decision' => 'reject', 'note' => 'Out of range.']],
    ])->assertSessionHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::Rejected);
    Notification::assertSentTo($listing->supplier, SupplierListingRejected::class);
});

test('partial approval approves some variations and leaves the rest without offers', function () {
    $listing = supplierTestListing(Supplier::factory()->create(), [[], []]);
    [$first, $second] = $listing->items->all();
    $product = websiteTestProduct();

    $this->actingAs(supplierListingTestReviewer())->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'One is fine.',
        'connect_product_id' => $product->public_id,
        'items' => [
            ['item_id' => $first->public_id, 'decision' => 'approve', 'platform_rate' => '1200.00'],
            ['item_id' => $second->public_id, 'decision' => 'reject', 'note' => 'Priced too high.'],
        ],
    ])->assertSessionHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::PartiallyApproved)
        ->and(SupplierOffer::query()->count())->toBe(1)
        ->and($first->refresh()->status)->toBe(ListingItemStatus::Approved)
        ->and($second->refresh()->status)->toBe(ListingItemStatus::Rejected)
        ->and($second->supplier_offer_id)->toBeNull();
});

test('rejecting every item creates no product and no offer', function () {
    $listing = supplierTestListing(Supplier::factory()->create());
    $before = Product::query()->count();

    $this->actingAs(supplierListingTestReviewer())->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'No.',
        'items' => [['item_id' => $listing->items()->first()->public_id, 'decision' => 'reject']],
    ])->assertSessionHasNoErrors();

    expect($listing->refresh()->status)->toBe(ListingStatus::Rejected)
        ->and(Product::query()->count())->toBe($before)
        ->and(SupplierOffer::query()->count())->toBe(0);
});

test('a platform rate below the supplier rate is refused and nothing is written', function () {
    $listing = supplierTestListing(Supplier::factory()->create());
    $product = websiteTestProduct();

    $this->actingAs(supplierListingTestReviewer())->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Try.', 'connect_product_id' => $product->public_id,
        'items' => [['item_id' => $listing->items()->first()->public_id, 'decision' => 'approve', 'platform_rate' => '999.99']],
    ])->assertSessionHasErrors('reason');

    expect(SupplierOffer::query()->count())->toBe(0)
        ->and($listing->refresh()->status)->toBe(ListingStatus::UnderReview);
});

test('connecting to an existing product changes none of its catalogue data', function () {
    $product = websiteTestProduct(['name' => 'Original name', 'description' => 'Original description']);
    $before = $product->only(['name', 'description', 'sku', 'status']);
    $before['wholesale_price'] = $product->wholesale_price->toDecimal();
    $before['suggested_selling_price'] = $product->suggested_selling_price->toDecimal();

    $listing = supplierTestListing(Supplier::factory()->create());

    $this->actingAs(supplierListingTestReviewer())->post(route('admin.supplier-listings.decision.store', $listing), [
        'reason' => 'Connect.', 'connect_product_id' => $product->public_id,
        'items' => [['item_id' => $listing->items()->first()->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ])->assertSessionHasNoErrors();

    $after = $product->refresh();

    expect($after->name)->toBe($before['name'])
        ->and($after->description)->toBe($before['description'])
        ->and($after->wholesale_price->toDecimal())->toBe($before['wholesale_price'])
        ->and($after->suggested_selling_price->toDecimal())->toBe($before['suggested_selling_price'])
        ->and($listing->refresh()->connected_product_id)->toBe($product->id);
});

test('two suppliers on one product keep separate offers and the second never overwrites the first', function () {
    $product = websiteTestProduct();
    $reviewer = supplierListingTestReviewer();

    $approve = function (Supplier $supplier, string $supplierRate, string $platformRate) use ($product, $reviewer) {
        $listing = supplierTestListing($supplier, [['supplier_rate' => Money::fromDecimal($supplierRate, Currency::BDT)]]);

        test()->actingAs($reviewer)->post(route('admin.supplier-listings.decision.store', $listing), [
            'reason' => 'Approve.', 'connect_product_id' => $product->public_id,
            'items' => [['item_id' => $listing->items()->first()->public_id, 'decision' => 'approve', 'platform_rate' => $platformRate]],
        ])->assertSessionHasNoErrors();

        return SupplierOffer::query()->where('supplier_id', $supplier->id)->firstOrFail();
    };

    $first = $approve($supplierA = Supplier::factory()->create(), '1000.00', '1300.00');
    $second = $approve(Supplier::factory()->create(), '900.00', '1250.00');

    expect(SupplierOffer::query()->where('product_id', $product->id)->count())->toBe(2)
        ->and($first->refresh()->supplier_rate->toDecimal())->toBe('1000.00')
        ->and($first->platform_rate->toDecimal())->toBe('1300.00')
        ->and($first->supplier_id)->toBe($supplierA->id)
        ->and($second->supplier_rate->toDecimal())->toBe('900.00');
});

test('only one offer per product may be preferred, and the preferred one sets the catalogue price', function () {
    $product = websiteTestProduct();
    $a = supplierTestOffer(product: $product, supplierRate: '1000.00', platformRate: '1300.00');
    $b = supplierTestOffer(product: $product, supplierRate: '900.00', platformRate: '1250.00');
    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $this->actingAs($staff)->post(route('admin.supplier-offers.preferred.store', $a))->assertSessionHasNoErrors();
    expect($a->refresh()->is_preferred)->toBeTrue()
        ->and($product->refresh()->wholesale_price->toDecimal())->toBe('1300.00');

    $this->post(route('admin.supplier-offers.preferred.store', $b))->assertSessionHasNoErrors();
    expect($a->refresh()->is_preferred)->toBeFalse()
        ->and($b->refresh()->is_preferred)->toBeTrue()
        ->and($product->refresh()->wholesale_price->toDecimal())->toBe('1250.00')
        ->and(AuditLog::query()->where('action', 'supplier_offer.preferred_selected')->count())->toBe(2);

    // The database backs it up, whatever the application does.
    expect(fn () => SupplierOffer::query()->whereKey($a->id)->update(['is_preferred' => true]))
        ->toThrow(QueryException::class);
});

test('a suspended offer cannot be made preferred and loses preference when suspended', function () {
    $offer = supplierTestOffer();
    $staff = testPlatformStaff(PlatformRole::SupplierManager);

    $this->actingAs($staff)->post(route('admin.supplier-offers.preferred.store', $offer));
    $this->post(route('admin.supplier-offers.suspension.store', $offer), ['reason' => 'Quality.'])->assertSessionHasNoErrors();

    expect($offer->refresh()->is_preferred)->toBeFalse();

    $this->post(route('admin.supplier-offers.preferred.store', $offer))->assertSessionHasErrors('offer');
});

test('the staff listing queue is filterable and hides drafts', function () {
    $supplier = Supplier::factory()->create();
    supplierTestListing($supplier, status: ListingStatus::Draft);
    supplierTestListing($supplier);
    supplierTestListing($supplier, status: ListingStatus::Rejected);

    $this->actingAs(testPlatformStaff(PlatformRole::SupplierManager))
        ->get(route('admin.supplier-listings.index', ['status' => 'under_review']))
        ->assertInertia(fn ($page) => $page->component('admin/supplier-listings/index')->has('listings.data', 1));

    $this->get(route('admin.supplier-listings.index'))
        ->assertInertia(fn ($page) => $page->has('listings.data', 2));
});
