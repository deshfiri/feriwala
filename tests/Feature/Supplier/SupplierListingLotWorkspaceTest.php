<?php

use App\Domain\Supplier\Actions\CreateSupplierListingLot;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * The Supplier's own multi-product batch workspace (Supplier Bulk Product
 * Listing, commit 3 of 5) -- adding, editing and removing product entries
 * inside a lot, on top of the schema laid down in
 * SupplierListingLotSchemaTest.php.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array<string, mixed> */
function supplierListingLotTestEntryPayload(array $overrides = []): array
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
            'supply_mode' => 'ready_stock',
            'available_quantity' => 40,
            'minimum_supply_quantity' => 2,
            'lead_time_days' => 3,
        ]],
        ...$overrides,
    ];
}

test('an approved supplier starts a batch and it opens as a draft', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());

    $this->post(route('supplier.listing-lots.store'), ['title' => 'Spring batch'])
        ->assertSessionHasNoErrors();

    $lot = SupplierProductListingLot::query()->firstOrFail();

    expect($lot->supplier_id)->toBe($supplier->id)
        ->and($lot->status)->toBe(LotStatus::Draft)
        ->and($lot->title)->toBe('Spring batch');
});

test('only an approved supplier can reach the batch workspace', function () {
    supplierTestSignIn(Supplier::factory()->kycPending()->create());

    $this->get(route('supplier.listing-lots.index'))->assertForbidden();
    $this->post(route('supplier.listing-lots.store'), ['title' => 'x'])->assertForbidden();
});

test('a supplier adds, edits and removes product entries while the batch is a draft', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);

    $this->post(route('supplier.listing-lots.items.store', $lot), supplierListingLotTestEntryPayload())
        ->assertSessionHasNoErrors();

    $entry = $lot->items()->firstOrFail();
    expect($entry->product_name)->toBe('Cotton panjabi')
        ->and($entry->status)->toBe(ListingStatus::Draft)
        ->and($entry->items()->count())->toBe(1);

    $this->patch(
        route('supplier.listing-lots.items.update', [$lot, $entry]),
        supplierListingLotTestEntryPayload(['product_name' => 'Cotton panjabi v2']),
    )->assertSessionHasNoErrors();
    expect($entry->refresh()->product_name)->toBe('Cotton panjabi v2');

    $this->post(route('supplier.listing-lots.items.archive', [$lot, $entry]))
        ->assertSessionHasNoErrors();
    expect($entry->refresh()->status)->toBe(ListingStatus::Archived);
});

test('a product entry only accepts the supplier rate, never a platform rate', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);

    $payload = supplierListingLotTestEntryPayload();
    $payload['items'][0]['platform_rate'] = '9999.00';

    $this->post(route('supplier.listing-lots.items.store', $lot), $payload)->assertSessionHasNoErrors();

    $item = $lot->items()->firstOrFail()->items()->firstOrFail();
    expect($item->supplier_rate->toDecimal())->toBe('1000.00')
        ->and($item->getAttributes())->not->toHaveKey('platform_rate');
});

test('editing or removing a product entry is refused once it leaves draft or correction_required', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);
    $this->post(route('supplier.listing-lots.items.store', $lot), supplierListingLotTestEntryPayload());
    $entry = $lot->items()->firstOrFail();
    $entry->forceFill(['status' => ListingStatus::UnderReview])->save();

    $this->patch(
        route('supplier.listing-lots.items.update', [$lot, $entry]),
        supplierListingLotTestEntryPayload(['product_name' => 'Sneaky']),
    )->assertSessionHasErrors('entry');
    $this->post(route('supplier.listing-lots.items.archive', [$lot, $entry]))
        ->assertSessionHasErrors('entry');

    expect($entry->refresh()->product_name)->toBe('Cotton panjabi')
        ->and($entry->refresh()->status)->toBe(ListingStatus::UnderReview);
});

test('new product entries can only be added while the batch is still a draft', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);
    $lot->forceFill(['status' => LotStatus::UnderReview])->save();

    $this->post(route('supplier.listing-lots.items.store', $lot), supplierListingLotTestEntryPayload())
        ->assertSessionHasErrors('entry');

    expect($lot->items()->count())->toBe(0);
});

test('a supplier can never reach another supplier\'s batch or its entries', function () {
    $mine = supplierTestSignIn(Supplier::factory()->create());
    $theirsSupplier = Supplier::factory()->create();
    $theirs = app(CreateSupplierListingLot::class)->handle($theirsSupplier);
    supplierTestSignIn($theirsSupplier);
    $this->post(route('supplier.listing-lots.items.store', $theirs), supplierListingLotTestEntryPayload());
    $theirEntry = $theirs->items()->firstOrFail();

    supplierTestSignIn($mine);

    $this->get(route('supplier.listing-lots.show', $theirs))->assertNotFound();
    $this->post(route('supplier.listing-lots.items.store', $theirs), supplierListingLotTestEntryPayload())->assertNotFound();
    $this->patch(route('supplier.listing-lots.items.update', [$theirs, $theirEntry]), supplierListingLotTestEntryPayload())->assertNotFound();
    $this->post(route('supplier.listing-lots.items.archive', [$theirs, $theirEntry]))->assertNotFound();
    $this->post(route('supplier.listing-lots.archive', $theirs))->assertNotFound();

    expect($theirEntry->refresh()->status)->toBe(ListingStatus::Draft)
        ->and(SupplierProductListing::query()->where('supplier_id', $mine->id)->count())->toBe(0);
});

test('an empty draft batch can be discarded, but one that ever held a product entry cannot', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $emptyLot = app(CreateSupplierListingLot::class)->handle($supplier);

    $this->post(route('supplier.listing-lots.archive', $emptyLot))->assertSessionHasNoErrors();
    expect($emptyLot->refresh()->status)->toBe(LotStatus::Closed);

    $usedLot = app(CreateSupplierListingLot::class)->handle($supplier);
    $this->post(route('supplier.listing-lots.items.store', $usedLot), supplierListingLotTestEntryPayload());
    $this->post(route('supplier.listing-lots.archive', $usedLot))->assertSessionHasErrors('lot');
    expect($usedLot->refresh()->status)->toBe(LotStatus::Draft);

    // Removing the only entry still leaves a row behind -- a listing is
    // never hard-deleted -- so the batch stays refused too.
    $usedLot->items()->firstOrFail()->transitionWithHistory(
        ListingStatus::Archived,
        new StatusChange(reason: 'test cleanup'),
        ['source' => SupplierStatusChangeSource::Supplier],
    );

    $this->post(route('supplier.listing-lots.archive', $usedLot))->assertSessionHasErrors('lot');
    expect($usedLot->refresh()->status)->toBe(LotStatus::Draft);
});
