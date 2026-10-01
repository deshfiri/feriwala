<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Supplier\Actions\DecideSupplierListingLot;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * Staff review of a whole listing lot (Supplier Bulk Product Listing,
 * commit 4 of 5): each product entry is decided independently
 * (DecideSupplierListingLot, the BulkSettleSupplierPayables idiom), then the
 * lot's own status rolls up from wherever every entry landed. Also covers
 * the openStock() guard corrections 5/6 on DecideSupplierListing itself.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function decideSupplierListingLotTestReviewer(): User
{
    $staff = testPlatformStaff(PlatformRole::SupplierManager);
    $staff->assignRole(PlatformRole::ProductManager->value);

    return $staff;
}

/** @param  array<string, mixed>  $itemOverrides */
function decideSupplierListingLotTestSubmittedLot(Supplier $supplier, array $entries): SupplierProductListingLot
{
    $lot = $supplier->lots()->create(['status' => LotStatus::UnderReview, 'submitted_at' => now()]);

    foreach ($entries as $entryOverrides) {
        $itemOverrides = $entryOverrides['item'] ?? [];
        unset($entryOverrides['item']);

        $entry = $lot->items()->create([
            'supplier_id' => $supplier->id,
            'product_name' => 'Cotton panjabi',
            'category_suggestion' => 'Menswear',
            'status' => ListingStatus::UnderReview,
            'submitted_at' => now(),
            ...$entryOverrides,
        ]);

        $entry->items()->create([
            'supplier_sku' => 'SUP-'.Str::upper(Str::random(6)),
            'supplier_rate' => Money::fromDecimal('1000.00', Currency::BDT),
            'currency_code' => 'BDT',
            'available_quantity' => 50,
            'minimum_supply_quantity' => 1,
            ...$itemOverrides,
        ]);
    }

    return $lot->refresh();
}

test('approving one entry and rejecting another rolls the lot up to partially approved', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[], []]);
    [$first, $second] = $lot->items->all();
    $product = websiteTestProduct();

    $results = app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [
        [
            'listing_id' => $first->public_id,
            'reason' => 'Looks right.',
            'product' => ['connect_product_id' => $product->public_id],
            'items' => [['item_id' => $first->items()->firstOrFail()->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
        ],
        [
            'listing_id' => $second->public_id,
            'reason' => 'Not suitable.',
            'items' => [['item_id' => $second->items()->firstOrFail()->public_id, 'decision' => 'reject', 'note' => 'Priced too high.']],
        ],
    ]);

    expect($results)->toHaveCount(2)
        ->and(collect($results)->every(fn (array $r) => $r['ok']))->toBeTrue()
        ->and($first->refresh()->status)->toBe(ListingStatus::Approved)
        ->and($second->refresh()->status)->toBe(ListingStatus::Rejected)
        ->and($lot->refresh()->status)->toBe(LotStatus::PartiallyApproved)
        ->and($lot->reviewed_by)->not->toBeNull();
});

test('approving every entry rolls the lot up to fully approved', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[]]);
    $entry = $lot->items->first();
    $product = websiteTestProduct();

    app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [[
        'listing_id' => $entry->public_id,
        'reason' => 'Approved.',
        'product' => ['connect_product_id' => $product->public_id],
        'items' => [['item_id' => $entry->items()->firstOrFail()->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ]]);

    expect($lot->refresh()->status)->toBe(LotStatus::Approved);
});

test('leaving one entry undecided keeps the lot at under review', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[], []]);
    [$first, $second] = $lot->items->all();
    $product = websiteTestProduct();

    app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [[
        'listing_id' => $first->public_id,
        'reason' => 'Approved.',
        'product' => ['connect_product_id' => $product->public_id],
        'items' => [['item_id' => $first->items()->firstOrFail()->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ]]);

    expect($first->refresh()->status)->toBe(ListingStatus::Approved)
        ->and($second->refresh()->status)->toBe(ListingStatus::UnderReview)
        ->and($lot->refresh()->status)->toBe(LotStatus::UnderReview);
});

test('one entry failing its own decision is reported and skipped, the other still succeeds', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[], []]);
    [$bad, $good] = $lot->items->all();
    $product = websiteTestProduct();

    $results = app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [
        [
            // Platform rate below the supplier rate -- DecideSupplierListing refuses this.
            'listing_id' => $bad->public_id,
            'reason' => 'Try.',
            'product' => ['connect_product_id' => $product->public_id],
            'items' => [['item_id' => $bad->items()->firstOrFail()->public_id, 'decision' => 'approve', 'platform_rate' => '1.00']],
        ],
        [
            'listing_id' => $good->public_id,
            'reason' => 'Approved.',
            'product' => ['connect_product_id' => $product->public_id],
            'items' => [['item_id' => $good->items()->firstOrFail()->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
        ],
    ]);

    $byId = collect($results)->keyBy('listing_id');

    expect($byId[$bad->public_id]['ok'])->toBeFalse()
        ->and($byId[$good->public_id]['ok'])->toBeTrue()
        ->and($bad->refresh()->status)->toBe(ListingStatus::UnderReview)
        ->and($good->refresh()->status)->toBe(ListingStatus::Approved)
        ->and($lot->refresh()->status)->toBe(LotStatus::UnderReview);
});

test('deciding a lot that is not awaiting review is refused', function () {
    $supplier = Supplier::factory()->create();
    $lot = $supplier->lots()->create(['status' => LotStatus::Draft]);

    expect(fn () => app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), []))
        ->toThrow(InvalidArgumentException::class);
});

test('an approved offer carries the supply mode declared on the listing item', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[
        'item' => ['supply_mode' => SupplyMode::OnDemand->value, 'available_quantity' => null, 'lead_time_days' => 5, 'fulfilment_capacity' => 10],
    ]]);
    $entry = $lot->items->first();
    $item = $entry->items()->firstOrFail();
    $product = websiteTestProduct();

    app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [[
        'listing_id' => $entry->public_id,
        'reason' => 'Approved.',
        'product' => ['connect_product_id' => $product->public_id],
        'items' => [['item_id' => $item->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ]]);

    $offer = SupplierOffer::query()->firstOrFail();

    expect($offer->supply_mode)->toBe(SupplyMode::OnDemand)
        ->and($offer->lead_time_days)->toBe(5)
        ->and($offer->fulfilment_capacity)->toBe(10)
        // No physical stock for a non-ready-stock offer (corrections 5/6).
        ->and($offer->stock)->toBeNull()
        ->and(SupplierStockMovement::query()->where('supplier_offer_id', $offer->id)->count())->toBe(0);
});

test('ready stock with no declared quantity and no staff override opens no stock either', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[
        'item' => ['supply_mode' => SupplyMode::ReadyStock->value, 'available_quantity' => null],
    ]]);
    $entry = $lot->items->first();
    $item = $entry->items()->firstOrFail();
    $product = websiteTestProduct();

    app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [[
        'listing_id' => $entry->public_id,
        'reason' => 'Approved, nothing declared yet.',
        'product' => ['connect_product_id' => $product->public_id],
        'items' => [['item_id' => $item->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00']],
    ]]);

    $offer = SupplierOffer::query()->firstOrFail();

    expect($offer->stock)->toBeNull()
        ->and($item->refresh()->status)->toBe(ListingItemStatus::Approved);
});

test('ready stock with no declared quantity opens stock once staff supplies an approved quantity', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[
        'item' => ['supply_mode' => SupplyMode::ReadyStock->value, 'available_quantity' => null],
    ]]);
    $entry = $lot->items->first();
    $item = $entry->items()->firstOrFail();
    $product = websiteTestProduct();

    app(DecideSupplierListingLot::class)->handle($lot, decideSupplierListingLotTestReviewer(), [[
        'listing_id' => $entry->public_id,
        'reason' => 'Approved with a staff-set opening figure.',
        'product' => ['connect_product_id' => $product->public_id],
        'items' => [['item_id' => $item->public_id, 'decision' => 'approve', 'platform_rate' => '1300.00', 'approved_quantity' => 20]],
    ]]);

    $offer = SupplierOffer::query()->firstOrFail();

    expect($offer->stock->quantity)->toBe(20);
});

test('a reviewer who cannot decide is refused by the http route', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[]]);
    $entry = $lot->items->first();

    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo(['supplier_listing.view']);

    $this->actingAs($viewer)->post(route('admin.supplier-listing-lots.decision.store', $lot), [
        'entries' => [[
            'listing_id' => $entry->public_id,
            'reason' => 'Try.',
            'items' => [['item_id' => $entry->items()->firstOrFail()->public_id, 'decision' => 'reject']],
        ]],
    ])->assertForbidden();

    expect($entry->refresh()->status)->toBe(ListingStatus::UnderReview);
});

test('the supplier rate never appears in the lot review payload without pricing access', function () {
    $supplier = Supplier::factory()->create();
    $lot = decideSupplierListingLotTestSubmittedLot($supplier, [[]]);

    $reviewer = User::factory()->staff()->create();
    $reviewer->givePermissionTo(['supplier_listing.view', 'supplier_listing.approve']);

    $response = $this->actingAs($reviewer)->get(route('admin.supplier-listing-lots.show', $lot));

    $response->assertInertia(fn ($page) => $page
        ->where('lot.may_view_pricing', false)
        ->where('lot.entries.0.items.0.supplier_rate', null));
});
