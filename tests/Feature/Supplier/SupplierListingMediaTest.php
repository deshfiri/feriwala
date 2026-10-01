<?php

use App\Domain\Supplier\Actions\ManageSupplierListingMedia;
use App\Domain\Supplier\Enums\ListingStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\SupplierListingMediaStore;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Real image upload for a Supplier product entry (Supplier Bulk Product
 * Listing, commit 2 of 5) -- the listing/listing item `images`/`documents`
 * jsonb columns were metadata-only placeholders with no store behind them;
 * this is that missing piece.
 */

beforeEach(function () {
    Storage::fake(SupplierListingMediaStore::DISK);
});

/**
 * Postgres aborts the whole transaction on a failed statement; see the
 * identically-named helper in SupplierListingLotSchemaTest.php for why this
 * wraps a deliberately-failing call in its own savepoint.
 */
function supplierListingMediaAssertRefused(Closure $callback): void
{
    try {
        DB::transaction($callback);
        test()->fail('Expected a QueryException to be thrown.');
    } catch (QueryException $exception) {
        expect($exception)->toBeInstanceOf(QueryException::class);
    }
}

it('stores a primary image under the listing, in position order', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    $media = app(ManageSupplierListingMedia::class)->add(
        $supplier,
        $listing,
        UploadedFile::fake()->image('front.png', 400, 300),
        SupplierListingMedia::ROLE_PRIMARY,
        'Front view of the product',
    );

    expect($media->role)->toBe(SupplierListingMedia::ROLE_PRIMARY)
        ->and($media->position)->toBe(1)
        ->and($media->width)->toBe(400)
        ->and($media->disk)->toBe(SupplierListingMediaStore::DISK)
        ->and(Storage::disk(SupplierListingMediaStore::DISK)->exists($media->path))->toBeTrue();
});

it('refuses an unaccepted file type', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    expect(fn () => app(ManageSupplierListingMedia::class)->add(
        $supplier,
        $listing,
        UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        SupplierListingMedia::ROLE_PRIMARY,
        'A PDF',
    ))->toThrow(InvalidArgumentException::class);

    expect(SupplierListingMedia::query()->count())->toBe(0);
});

it('refuses a blank alt text', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    expect(fn () => app(ManageSupplierListingMedia::class)->add(
        $supplier,
        $listing,
        UploadedFile::fake()->image('front.png'),
        SupplierListingMedia::ROLE_PRIMARY,
        '   ',
    ))->toThrow(InvalidArgumentException::class);
});

it('enforces exactly one primary image at the application layer', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    app(ManageSupplierListingMedia::class)->add($supplier, $listing, UploadedFile::fake()->image('a.png'), SupplierListingMedia::ROLE_PRIMARY, 'A');

    expect(fn () => app(ManageSupplierListingMedia::class)->add(
        $supplier, $listing, UploadedFile::fake()->image('b.png'), SupplierListingMedia::ROLE_PRIMARY, 'B',
    ))->toThrow(InvalidArgumentException::class);

    expect(SupplierListingMedia::query()->where('role', SupplierListingMedia::ROLE_PRIMARY)->count())->toBe(1);
});

it('enforces exactly one primary image at the database layer too', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    $listing->media()->create([
        'public_id' => (string) Str::ulid(),
        'role' => SupplierListingMedia::ROLE_PRIMARY,
        'disk' => SupplierListingMediaStore::DISK,
        'path' => 'x.png', 'mime_type' => 'image/png', 'size_bytes' => 100, 'position' => 1,
        'alt_text' => 'A',
    ]);

    supplierListingMediaAssertRefused(fn () => $listing->media()->create([
        'public_id' => (string) Str::ulid(),
        'role' => SupplierListingMedia::ROLE_PRIMARY,
        'disk' => SupplierListingMediaStore::DISK,
        'path' => 'y.png', 'mime_type' => 'image/png', 'size_bytes' => 100, 'position' => 2,
        'alt_text' => 'B',
    ]));
});

it('becomes immutable once the listing leaves draft or correction_required', function () {
    $supplier = Supplier::factory()->create();
    $listing = supplierTestListing($supplier, status: ListingStatus::UnderReview);

    $media = $listing->media()->create([
        'public_id' => (string) Str::ulid(),
        'role' => SupplierListingMedia::ROLE_PRIMARY,
        'disk' => SupplierListingMediaStore::DISK,
        'path' => 'x.png', 'mime_type' => 'image/png', 'size_bytes' => 100, 'position' => 1,
        'alt_text' => 'A',
    ]);

    // Application layer refuses first...
    expect(fn () => app(ManageSupplierListingMedia::class)->describe($supplier, $media, ['alt_text' => 'Changed']))
        ->toThrow(InvalidArgumentException::class);

    // ...and the database refuses it too, whatever issues the statement.
    supplierListingMediaAssertRefused(fn () => $media->forceFill(['alt_text' => 'Tampered'])->save());
    supplierListingMediaAssertRefused(fn () => $media->delete());
});

it('deletes the stored file when the database write fails', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    // Fill the listing to its cap so the next add() fails after the file is
    // already written to disk.
    for ($i = 0; $i < ManageSupplierListingMedia::MAX_PER_LISTING; $i++) {
        app(ManageSupplierListingMedia::class)->add(
            $supplier, $listing, UploadedFile::fake()->image("g{$i}.png"), SupplierListingMedia::ROLE_GALLERY, "Image {$i}",
        );
    }

    $before = count(Storage::disk(SupplierListingMediaStore::DISK)->allFiles());

    expect(fn () => app(ManageSupplierListingMedia::class)->add(
        $supplier, $listing, UploadedFile::fake()->image('overflow.png'), SupplierListingMedia::ROLE_GALLERY, 'Overflow',
    ))->toThrow(InvalidArgumentException::class);

    expect(Storage::disk(SupplierListingMediaStore::DISK)->allFiles())->toHaveCount($before);
});

it('refuses another supplier\'s listing', function () {
    $owner = Supplier::factory()->create();
    $listing = $owner->listings()->create(['product_name' => 'A product']);
    $stranger = Supplier::factory()->create();

    expect(fn () => app(ManageSupplierListingMedia::class)->add(
        $stranger, $listing, UploadedFile::fake()->image('a.png'), SupplierListingMedia::ROLE_PRIMARY, 'A',
    ))->toThrow(InvalidArgumentException::class);
});

it('reorders a listing\'s own images', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    $a = app(ManageSupplierListingMedia::class)->add($supplier, $listing, UploadedFile::fake()->image('a.png'), SupplierListingMedia::ROLE_GALLERY, 'A');
    $b = app(ManageSupplierListingMedia::class)->add($supplier, $listing, UploadedFile::fake()->image('b.png'), SupplierListingMedia::ROLE_GALLERY, 'B');

    app(ManageSupplierListingMedia::class)->reorder($supplier, $listing, [$b->public_id, $a->public_id]);

    expect($b->fresh()->position)->toBe(1)
        ->and($a->fresh()->position)->toBe(2);
});

it('removes an image and closes the position gap, deleting the file', function () {
    $supplier = Supplier::factory()->create();
    $listing = $supplier->listings()->create(['product_name' => 'A product']);

    $a = app(ManageSupplierListingMedia::class)->add($supplier, $listing, UploadedFile::fake()->image('a.png'), SupplierListingMedia::ROLE_GALLERY, 'A');
    $b = app(ManageSupplierListingMedia::class)->add($supplier, $listing, UploadedFile::fake()->image('b.png'), SupplierListingMedia::ROLE_GALLERY, 'B');
    $path = $a->path;

    app(ManageSupplierListingMedia::class)->remove($supplier, $a);

    expect(SupplierListingMedia::query()->find($a->id))->toBeNull()
        ->and($b->fresh()->position)->toBe(1)
        ->and(Storage::disk(SupplierListingMediaStore::DISK)->exists($path))->toBeFalse();
});
