<?php

use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierProductListing;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A Supplier's Product Listing Requests over the mobile API (D25, P13-9): the
 * same actions, scoping and rules as the web `supplier.listings.*` routes.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function mobileSupplierListingAs(Supplier $supplier): void
{
    test()->withToken($supplier->createToken('Pixel 8')->plainTextToken);
}

/** @return array<string, mixed> */
function mobileSupplierListingPayload(array $overrides = []): array
{
    return [
        'product_name' => 'Cotton panjabi',
        'description' => 'Soft cotton.',
        'category_suggestion' => 'Menswear',
        'items' => [[
            'supplier_sku' => 'PJ-001',
            'supplier_rate' => '1000.50',
            'currency_code' => 'BDT',
            'available_quantity' => 40,
            'minimum_supply_quantity' => 2,
            'lead_time_days' => 3,
        ]],
        ...$overrides,
    ];
}

test('a listing endpoint needs a token', function () {
    $this->getJson(route('mobile.v1.supplier.listings.index'))->assertUnauthorized();
    $this->postJson(route('mobile.v1.supplier.listings.store'), mobileSupplierListingPayload())->assertUnauthorized();
});

test('a supplier that is not operational is refused every listing endpoint', function () {
    $supplier = Supplier::factory()->suspended()->create();
    $listing = $supplier->listings()->create(['product_name' => 'Mine']);
    mobileSupplierListingAs($supplier);

    $this->getJson(route('mobile.v1.supplier.listings.index'))
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
    $this->postJson(route('mobile.v1.supplier.listings.store'), mobileSupplierListingPayload())
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
    $this->getJson(route('mobile.v1.supplier.listings.show', $listing->public_id))
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
});

test('a supplier drafts, reads, edits and lists a listing with exact Taka', function () {
    $supplier = Supplier::factory()->create();
    mobileSupplierListingAs($supplier);

    $id = $this->postJson(route('mobile.v1.supplier.listings.store'), mobileSupplierListingPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', ListingStatus::Draft->value)
        ->assertJsonPath('data.is_editable', true)
        ->assertJsonPath('data.items.0.supplier_rate.amount', '1000.50')
        ->json('data.id');

    $this->getJson(route('mobile.v1.supplier.listings.show', $id))
        ->assertOk()->assertJsonPath('data.product_name', 'Cotton panjabi');

    $this->patchJson(
        route('mobile.v1.supplier.listings.update', $id),
        mobileSupplierListingPayload(['product_name' => 'Cotton panjabi v2']),
    )->assertOk()->assertJsonPath('data.product_name', 'Cotton panjabi v2');

    $this->getJson(route('mobile.v1.supplier.listings.index', ['status' => 'draft']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('meta.total', 1);

    $this->getJson(route('mobile.v1.supplier.listings.options'))
        ->assertOk()->assertJsonStructure(['data' => ['categories', 'brands', 'attributes']]);
});

test('a listing refuses a malformed or excess-precision rate', function () {
    mobileSupplierListingAs(Supplier::factory()->create());

    $payload = mobileSupplierListingPayload();
    $payload['items'][0]['supplier_rate'] = '10.505';

    $this->postJson(route('mobile.v1.supplier.listings.store'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.supplier_rate');
});

test('a supplier submits a listing for review and may then no longer edit or archive it', function () {
    $supplier = Supplier::factory()->create();
    mobileSupplierListingAs($supplier);

    $id = $this->postJson(route('mobile.v1.supplier.listings.store'), mobileSupplierListingPayload())->json('data.id');

    $this->postJson(route('mobile.v1.supplier.listings.submission.store', $id))
        ->assertOk()->assertJsonPath('data.status', ListingStatus::UnderReview->value);

    $this->patchJson(route('mobile.v1.supplier.listings.update', $id), mobileSupplierListingPayload())
        ->assertUnprocessable()->assertJsonValidationErrors('listing');
    $this->postJson(route('mobile.v1.supplier.listings.archive', $id))
        ->assertUnprocessable()->assertJsonValidationErrors('listing');
});

test('a supplier archives a draft listing', function () {
    $supplier = Supplier::factory()->create();
    mobileSupplierListingAs($supplier);

    $id = $this->postJson(route('mobile.v1.supplier.listings.store'), mobileSupplierListingPayload())->json('data.id');

    $this->postJson(route('mobile.v1.supplier.listings.archive', $id))
        ->assertOk()->assertJsonPath('data.status', ListingStatus::Archived->value);
});

test('a listing that belongs to a batch is submitted with the batch, not alone', function () {
    $supplier = Supplier::factory()->create();
    $lot = $supplier->lots()->create(['title' => 'Batch']);
    $entry = $supplier->listings()->create(['product_name' => 'In batch', 'lot_id' => $lot->id]);
    mobileSupplierListingAs($supplier);

    $this->postJson(route('mobile.v1.supplier.listings.submission.store', $entry->public_id))
        ->assertUnprocessable()->assertJsonValidationErrors('listing');
});

test('a supplier can never reach another supplier\'s listing', function () {
    $mine = Supplier::factory()->create();
    $theirs = Supplier::factory()->create();
    $theirListing = $theirs->listings()->create(['product_name' => 'Theirs']);
    mobileSupplierListingAs($mine);

    $this->getJson(route('mobile.v1.supplier.listings.show', $theirListing->public_id))->assertNotFound();
    $this->patchJson(route('mobile.v1.supplier.listings.update', $theirListing->public_id), mobileSupplierListingPayload())->assertNotFound();
    $this->postJson(route('mobile.v1.supplier.listings.submission.store', $theirListing->public_id))->assertNotFound();
    $this->postJson(route('mobile.v1.supplier.listings.archive', $theirListing->public_id))->assertNotFound();
    $this->getJson(route('mobile.v1.supplier.listings.index'))->assertOk()->assertJsonCount(0, 'data');

    expect($theirListing->refresh()->status)->toBe(ListingStatus::Draft)
        ->and(SupplierProductListing::query()->where('supplier_id', $mine->id)->count())->toBe(0);
});
