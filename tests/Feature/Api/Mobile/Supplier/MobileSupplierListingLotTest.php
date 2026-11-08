<?php

use App\Domain\Supplier\Actions\ManageSupplierListingMedia;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Enums\LotStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\Models\SupplierProductListing;
use App\Domain\Supplier\SupplierListingMediaStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * A Supplier's draft-then-submit-once listing batches over the mobile API: the
 * same actions, scoping and rules as the web `supplier.listing-lots.*` routes.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(SupplierListingMediaStore::DISK);
});

function mobileSupplierLotAs(Supplier $supplier): void
{
    test()->withToken($supplier->createToken('Pixel 8')->plainTextToken);
}

/** @return array<string, mixed> */
function mobileSupplierLotEntryPayload(array $overrides = []): array
{
    return [
        'product_name' => 'Cotton panjabi',
        'category_suggestion' => 'Menswear',
        'items' => [[
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

test('listing lot endpoints need a token', function () {
    $this->getJson(route('mobile.v1.supplier.listing-lots.index'))->assertUnauthorized();
    $this->postJson(route('mobile.v1.supplier.listing-lots.store'))->assertUnauthorized();
});

test('a supplier that is not operational is refused every lot endpoint', function () {
    $supplier = Supplier::factory()->suspended()->create();
    $lot = $supplier->lots()->create(['title' => 'Mine']);
    mobileSupplierLotAs($supplier);

    $this->getJson(route('mobile.v1.supplier.listing-lots.index'))
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
    $this->postJson(route('mobile.v1.supplier.listing-lots.store'), ['title' => 'x'])
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
    $this->postJson(route('mobile.v1.supplier.listing-lots.items.store', $lot->public_id), mobileSupplierLotEntryPayload())
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
});

test('a supplier starts a batch, adds, edits and removes entries, and lists it', function () {
    $supplier = Supplier::factory()->create();
    mobileSupplierLotAs($supplier);

    $lotId = $this->postJson(route('mobile.v1.supplier.listing-lots.store'), ['title' => 'Spring batch'])
        ->assertCreated()
        ->assertJsonPath('data.status', LotStatus::Draft->value)
        ->assertJsonPath('data.is_draft', true)
        ->json('data.id');

    $entryId = $this->postJson(route('mobile.v1.supplier.listing-lots.items.store', $lotId), mobileSupplierLotEntryPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', ListingStatus::Draft->value)
        ->assertJsonPath('data.lot_id', $lotId)
        ->assertJsonPath('data.items.0.supplier_rate.amount', '1000.00')
        ->json('data.id');

    $this->patchJson(
        route('mobile.v1.supplier.listing-lots.items.update', [$lotId, $entryId]),
        mobileSupplierLotEntryPayload(['product_name' => 'Cotton panjabi v2']),
    )->assertOk()->assertJsonPath('data.product_name', 'Cotton panjabi v2');

    $this->getJson(route('mobile.v1.supplier.listing-lots.show', $lotId))
        ->assertOk()
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.id', $entryId);

    $this->getJson(route('mobile.v1.supplier.listing-lots.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $lotId)
        ->assertJsonPath('data.0.item_count', 1);

    $this->postJson(route('mobile.v1.supplier.listing-lots.items.archive', [$lotId, $entryId]))
        ->assertOk()->assertJsonPath('data.status', ListingStatus::Archived->value);
});

test('an entry only accepts the supplier rate and refuses an excess-precision one', function () {
    $supplier = Supplier::factory()->create();
    $lot = $supplier->lots()->create(['title' => 'Mine']);
    mobileSupplierLotAs($supplier);

    $payload = mobileSupplierLotEntryPayload();
    $payload['items'][0]['supplier_rate'] = '10.505';

    $this->postJson(route('mobile.v1.supplier.listing-lots.items.store', $lot->public_id), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.supplier_rate');
});

test('a batch with a primary image is submitted once and then is closed to changes', function () {
    $supplier = Supplier::factory()->create();
    mobileSupplierLotAs($supplier);

    $lotId = $this->postJson(route('mobile.v1.supplier.listing-lots.store'), ['title' => 'Batch'])->json('data.id');
    $entryId = $this->postJson(route('mobile.v1.supplier.listing-lots.items.store', $lotId), mobileSupplierLotEntryPayload())->json('data.id');
    $entry = SupplierProductListing::query()->where('public_id', $entryId)->firstOrFail();
    app(ManageSupplierListingMedia::class)->add($supplier, $entry, UploadedFile::fake()->image('front.png', 400, 300), SupplierListingMedia::ROLE_PRIMARY, 'Front view');

    $this->postJson(route('mobile.v1.supplier.listing-lots.submission.store', $lotId))
        ->assertOk()->assertJsonPath('data.status', LotStatus::UnderReview->value);

    $this->postJson(route('mobile.v1.supplier.listing-lots.submission.store', $lotId))
        ->assertUnprocessable();
    $this->postJson(route('mobile.v1.supplier.listing-lots.items.store', $lotId), mobileSupplierLotEntryPayload())
        ->assertUnprocessable()->assertJsonValidationErrors('entry');
    $this->postJson(route('mobile.v1.supplier.listing-lots.archive', $lotId))
        ->assertUnprocessable()->assertJsonValidationErrors('lot');
});

test('an empty draft batch can be discarded', function () {
    $supplier = Supplier::factory()->create();
    mobileSupplierLotAs($supplier);

    $lotId = $this->postJson(route('mobile.v1.supplier.listing-lots.store'))->json('data.id');

    $this->postJson(route('mobile.v1.supplier.listing-lots.archive', $lotId))
        ->assertOk()->assertJsonPath('data.status', LotStatus::Closed->value);
});

test('a supplier can never reach another supplier\'s batch or its entries', function () {
    $theirs = Supplier::factory()->create();
    $lot = $theirs->lots()->create(['title' => 'Theirs']);
    $entry = $theirs->listings()->create(['product_name' => 'Entry', 'lot_id' => $lot->id]);
    mobileSupplierLotAs(Supplier::factory()->create());

    $this->getJson(route('mobile.v1.supplier.listing-lots.show', $lot->public_id))->assertNotFound();
    $this->postJson(route('mobile.v1.supplier.listing-lots.submission.store', $lot->public_id))->assertNotFound();
    $this->postJson(route('mobile.v1.supplier.listing-lots.archive', $lot->public_id))->assertNotFound();
    $this->postJson(route('mobile.v1.supplier.listing-lots.items.store', $lot->public_id), mobileSupplierLotEntryPayload())->assertNotFound();
    $this->patchJson(route('mobile.v1.supplier.listing-lots.items.update', [$lot->public_id, $entry->public_id]), mobileSupplierLotEntryPayload())->assertNotFound();
    $this->postJson(route('mobile.v1.supplier.listing-lots.items.archive', [$lot->public_id, $entry->public_id]))->assertNotFound();
    $this->getJson(route('mobile.v1.supplier.listing-lots.index'))->assertOk()->assertJsonCount(0, 'data');

    expect($lot->refresh()->status)->toBe(LotStatus::Draft)
        ->and($entry->refresh()->status)->toBe(ListingStatus::Draft);
});
