<?php

use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\SupplierListingMediaStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * A Supplier's listing images over the mobile API: the same
 * ManageSupplierListingMedia action, private disk and ownership rules as web.
 */

beforeEach(function () {
    Storage::fake(SupplierListingMediaStore::DISK);
});

function mobileSupplierMediaAs(Supplier $supplier): void
{
    test()->withToken($supplier->createToken('Pixel 8')->plainTextToken);
}

/** @return array<string, mixed> */
function mobileSupplierMediaPayload(string $role = 'primary'): array
{
    return [
        'file' => UploadedFile::fake()->image('front.png', 400, 300),
        'role' => $role,
        'alt_text' => 'Front view',
    ];
}

test('listing media endpoints need a token', function () {
    $this->postJson(route('mobile.v1.supplier.listings.media.store', 'x'), [])->assertUnauthorized();
    $this->getJson(route('mobile.v1.supplier.listings.media.download', 'x'))->assertUnauthorized();
});

test('a supplier that is not operational is refused the media endpoints', function () {
    $supplier = Supplier::factory()->suspended()->create();
    $listing = $supplier->listings()->create(['product_name' => 'Mine']);
    mobileSupplierMediaAs($supplier);

    $this->postJson(route('mobile.v1.supplier.listings.media.store', $listing->public_id), mobileSupplierMediaPayload())
        ->assertForbidden()->assertJsonPath('code', 'supplier_not_operational');
});

test('a supplier uploads, edits, reorders, downloads and removes an image', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'Mine']);
    mobileSupplierMediaAs($supplier);

    $primary = $this->post(
        route('mobile.v1.supplier.listings.media.store', $listing->public_id),
        mobileSupplierMediaPayload(),
        ['Accept' => 'application/json'],
    )->assertCreated()->assertJsonPath('data.role', 'primary')->json('data.id');

    $gallery = $this->post(
        route('mobile.v1.supplier.listings.media.store', $listing->public_id),
        mobileSupplierMediaPayload('gallery'),
        ['Accept' => 'application/json'],
    )->assertCreated()->json('data.id');

    $stored = SupplierListingMedia::query()->where('public_id', $primary)->firstOrFail();
    expect($stored->disk)->toBe(SupplierListingMediaStore::DISK)
        ->and(Storage::disk(SupplierListingMediaStore::DISK)->exists($stored->path))->toBeTrue();

    $this->patchJson(route('mobile.v1.supplier.listings.media.update', [$listing->public_id, $gallery]), ['alt_text' => 'Side view'])
        ->assertOk()->assertJsonPath('data.alt_text', 'Side view');

    $this->postJson(route('mobile.v1.supplier.listings.media.reorder', $listing->public_id), ['order' => [$gallery, $primary]])
        ->assertOk()->assertJsonPath('data.0.id', $gallery);

    $this->get(route('mobile.v1.supplier.listings.media.download', $primary))
        ->assertOk()->assertHeader('Content-Type', $stored->mime_type);

    $this->deleteJson(route('mobile.v1.supplier.listings.media.destroy', [$listing->public_id, $gallery]))->assertNoContent();
    expect(SupplierListingMedia::query()->where('public_id', $gallery)->exists())->toBeFalse();
});

test('an unaccepted file type is refused', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'Mine']);
    mobileSupplierMediaAs($supplier);

    $this->post(
        route('mobile.v1.supplier.listings.media.store', $listing->public_id),
        [...mobileSupplierMediaPayload(), 'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ['Accept' => 'application/json'],
    )->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('a supplier can never touch or download another supplier\'s image', function () {
    $owner = Supplier::factory()->create();
    $listing = $owner->listings()->create(['product_name' => 'Theirs']);
    mobileSupplierMediaAs($owner);
    $media = $this->post(
        route('mobile.v1.supplier.listings.media.store', $listing->public_id),
        mobileSupplierMediaPayload(),
        ['Accept' => 'application/json'],
    )->json('data.id');

    $intruder = Supplier::factory()->create();
    $this->app['auth']->forgetGuards();
    mobileSupplierMediaAs($intruder);

    $this->postJson(route('mobile.v1.supplier.listings.media.store', $listing->public_id), mobileSupplierMediaPayload())->assertNotFound();
    $this->patchJson(route('mobile.v1.supplier.listings.media.update', [$listing->public_id, $media]), ['alt_text' => 'x'])->assertNotFound();
    $this->deleteJson(route('mobile.v1.supplier.listings.media.destroy', [$listing->public_id, $media]))->assertNotFound();
    $this->getJson(route('mobile.v1.supplier.listings.media.download', $media))->assertNotFound();

    expect(SupplierListingMedia::query()->where('public_id', $media)->exists())->toBeTrue();
});
