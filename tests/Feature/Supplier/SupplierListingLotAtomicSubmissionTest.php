<?php

use App\Domain\Supplier\Actions\CreateSupplierListingLot;
use App\Domain\Supplier\Actions\SubmitSupplierListingLot;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\Models\SupplierProductListingLot;
use App\Domain\Supplier\SupplierListingMediaStore;
use App\Notifications\Supplier\SupplierListingLotSubmitted;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Atomic, all-or-nothing lot submission (Supplier Bulk Product Listing,
 * commit 3 of 5, correction 3): a Supplier's submission either takes every
 * eligible product entry in the batch or changes nothing at all -- never a
 * partial outcome. Partial outcomes belong only to staff review
 * (commit 4's DecideSupplierListingLot), not here.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
});

/** Attaches a primary image directly, bypassing the upload pipeline already covered by SupplierListingMediaTest.php. */
function supplierListingLotTestGivePrimaryImage(SupplierProductListing $entry): SupplierListingMedia
{
    return $entry->media()->create([
        'public_id' => (string) Str::ulid(),
        'role' => SupplierListingMedia::ROLE_PRIMARY,
        'disk' => SupplierListingMediaStore::DISK,
        'path' => 'x.png',
        'mime_type' => 'image/png',
        'size_bytes' => 100,
        'position' => 1,
        'alt_text' => 'Front view',
    ]);
}

function supplierListingLotTestAddEntry(Supplier $supplier, SupplierProductListingLot $lot, string $name = 'Cotton panjabi'): SupplierProductListing
{
    $entry = $lot->items()->create([
        'supplier_id' => $supplier->id,
        'product_name' => $name,
        'category_suggestion' => 'Menswear',
    ]);
    $entry->items()->create([
        'supplier_sku' => 'SKU-'.Str::upper(Str::random(6)),
        'supplier_rate' => Money::fromDecimal('1000.00', Currency::BDT),
        'available_quantity' => 10,
    ]);

    return $entry;
}

test('one entry missing its primary image blocks the whole batch and changes nothing', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);

    $ready = supplierListingLotTestAddEntry($supplier, $lot, 'Ready product');
    supplierListingLotTestGivePrimaryImage($ready);

    $missingImage = supplierListingLotTestAddEntry($supplier, $lot, 'Missing image product');
    // Deliberately no primary image on this one.

    $this->post(route('supplier.listing-lots.submission.store', $lot))
        ->assertSessionHasErrors("items.{$missingImage->public_id}");

    expect($lot->refresh()->status)->toBe(LotStatus::Draft)
        ->and($ready->refresh()->status)->toBe(ListingStatus::Draft)
        ->and($missingImage->refresh()->status)->toBe(ListingStatus::Draft);

    Notification::assertNothingSent();
});

test('a fully valid batch submits every entry atomically and the batch moves to under review', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);

    $first = supplierListingLotTestAddEntry($supplier, $lot, 'First product');
    supplierListingLotTestGivePrimaryImage($first);
    $second = supplierListingLotTestAddEntry($supplier, $lot, 'Second product');
    supplierListingLotTestGivePrimaryImage($second);

    $this->post(route('supplier.listing-lots.submission.store', $lot))->assertSessionHasNoErrors();

    expect($lot->refresh()->status)->toBe(LotStatus::UnderReview)
        ->and($first->refresh()->status)->toBe(ListingStatus::UnderReview)
        ->and($second->refresh()->status)->toBe(ListingStatus::UnderReview);

    Notification::assertSentTo($supplier, SupplierListingLotSubmitted::class);
});

test('an entry with no variation at all also blocks the whole batch', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);

    $withVariant = supplierListingLotTestAddEntry($supplier, $lot);
    supplierListingLotTestGivePrimaryImage($withVariant);

    $empty = $lot->items()->create([
        'supplier_id' => $supplier->id,
        'product_name' => 'No variation',
        'category_suggestion' => 'Menswear',
    ]);
    supplierListingLotTestGivePrimaryImage($empty);

    $this->post(route('supplier.listing-lots.submission.store', $lot))
        ->assertSessionHasErrors("items.{$empty->public_id}");

    expect($lot->refresh()->status)->toBe(LotStatus::Draft)
        ->and($withVariant->refresh()->status)->toBe(ListingStatus::Draft);
});

test('resubmitting a fully decided batch is refused as a clean no-op, not a partial resubmission', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());
    $lot = app(CreateSupplierListingLot::class)->handle($supplier);
    $entry = supplierListingLotTestAddEntry($supplier, $lot);
    supplierListingLotTestGivePrimaryImage($entry);

    app(SubmitSupplierListingLot::class)->handle($supplier, $lot);
    expect($lot->refresh()->status)->toBe(LotStatus::UnderReview);

    expect(fn () => app(SubmitSupplierListingLot::class)->handle($supplier, $lot->refresh()))
        ->toThrow(ValidationException::class);

    // Still exactly one submission notification -- the no-op sent nothing more.
    Notification::assertSentToTimes($supplier, SupplierListingLotSubmitted::class, 1);
});

test('another supplier can never submit or view someone else\'s batch', function () {
    $owner = Supplier::factory()->create();
    $lot = app(CreateSupplierListingLot::class)->handle($owner);
    $entry = supplierListingLotTestAddEntry($owner, $lot);
    supplierListingLotTestGivePrimaryImage($entry);

    supplierTestSignIn(Supplier::factory()->create());

    $this->post(route('supplier.listing-lots.submission.store', $lot))->assertNotFound();

    expect($lot->refresh()->status)->toBe(LotStatus::Draft);
});
