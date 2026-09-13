<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageBrands;
use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Brand;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Product brands (P3-2, §11.3, §12).
 *
 * Three things carry this file. Writing a brand is a platform privilege, refused
 * at the controller rather than by hiding a button. A brand name is unique
 * whatever its casing, in the database as well as in validation. And the logo is
 * an upload somebody else's browser chose: its type is read from its bytes, its
 * size is bounded, and the file on disk always matches the row pointing at it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('public');

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
});

function catalogBrand(array $attributes = []): Brand
{
    return Brand::create([
        'name' => 'Walton',
        'is_active' => true,
        ...$attributes,
    ]);
}

describe('only the platform writes brands (§12)', function () {
    it('refuses a business account holder, and stores none of their file', function () {
        $account = testBusinessAccount(AccountStatus::Active);

        $this->actingAs($account->owner)
            ->get(route('admin.catalog.brands.index'))
            ->assertForbidden();

        $this->actingAs($account->owner)
            ->post(route('admin.catalog.brands.store'), [
                'name' => 'Mine',
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertForbidden();

        expect(Brand::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses every write to staff who may only read the catalogue', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $brand = catalogBrand();

        $this->actingAs($viewer)->get(route('admin.catalog.brands.index'))->assertOk();

        $this->actingAs($viewer)
            ->post(route('admin.catalog.brands.store'), ['name' => 'Mine'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('admin.catalog.brands.update', $brand->public_id), ['name' => 'Renamed'])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('admin.catalog.brands.active', $brand->public_id), ['is_active' => false])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('admin.catalog.brands.destroy', $brand->public_id))
            ->assertForbidden();

        expect($brand->refresh()->name)->toBe('Walton')
            ->and($brand->is_active)->toBeTrue();
    });

    it('refuses staff with no catalogue permission at all', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.catalog.brands.index'))
            ->assertForbidden();
    });

    it('lets a product manager add a brand with its logo', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), [
                'name' => 'Walton',
                'logo' => UploadedFile::fake()->image('Walton Logo (final).png', 200, 200),
                'logo_alt' => 'Walton wordmark',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $brand = Brand::query()->firstOrFail();

        // A random name under the brands folder — nothing of the original
        // filename survives.
        expect($brand->logo_path)->toMatch('#^catalog/brands/[0-9a-f]{32}\.png$#')
            ->and($brand->logo_alt)->toBe('Walton wordmark');

        Storage::disk('public')->assertExists((string) $brand->logo_path);
    });
});

describe('validation and database constraints', function () {
    it('requires a name', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), ['name' => ''])
            ->assertSessionHasErrors('name');
    });

    it('makes a slug from the name, and takes one that was typed', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), ['name' => 'Pran RFL']);
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), ['name' => 'Square Toiletries', 'slug' => 'square']);

        expect(Brand::query()->pluck('slug')->sort()->values()->all())->toBe(['pran-rfl', 'square']);
    });

    it('refuses a name another brand holds in different capitals', function () {
        catalogBrand(['name' => 'Samsung']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), ['name' => 'SAMSUNG'])
            ->assertSessionHasErrors('name');

        expect(Brand::query()->count())->toBe(1);
    });

    it('lets a brand keep its own name when edited', function () {
        $brand = catalogBrand(['name' => 'Samsung']);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.update', $brand->public_id), [
                'name' => 'Samsung',
                'description' => 'Electronics',
            ])
            ->assertSessionHasNoErrors();

        expect($brand->refresh()->description)->toBe('Electronics');
    });

    it('holds the name unique in the database whatever the casing', function () {
        // Validation catches the ordinary case; the functional index is what
        // holds when two administrators add the same brand at the same moment.
        catalogBrand(['name' => 'Samsung']);

        expect(fn () => Brand::create(['name' => 'samsung', 'slug' => 'samsung-bd']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('holds the slug unique in the database', function () {
        catalogBrand(['name' => 'Walton', 'slug' => 'walton']);

        expect(fn () => Brand::create(['name' => 'Walton Hi-Tech', 'slug' => 'walton']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a slug with characters a URL cannot carry', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), ['name' => 'Walton', 'slug' => 'Walton Ltd!'])
            ->assertSessionHasErrors('slug');
    });
});

describe('the logo is an untrusted upload', function () {
    it('refuses a document, and writes nothing', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), [
                'name' => 'Walton',
                'logo' => UploadedFile::fake()->create('brochure.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors('logo');

        expect(Brand::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses an SVG, which can carry script', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), [
                'name' => 'Walton',
                'logo' => UploadedFile::fake()->createWithContent(
                    'logo.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            ])
            ->assertSessionHasErrors('logo');

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('judges the file by its bytes, not the name it was given', function () {
        /*
         * A real file rather than a fake: a fake reports whatever type it is
         * told, and the point here is that a page of HTML renamed `logo.png`
         * and labelled `image/png` by the browser is still HTML.
         */
        $path = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, '<!doctype html><html><body><script>alert(1)</script></body></html>');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), [
                'name' => 'Walton',
                'logo' => new UploadedFile($path, 'logo.png', 'image/png', null, true),
            ])
            ->assertSessionHasErrors('logo');

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses an image over the size limit', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.brands.store'), [
                'name' => 'Walton',
                'logo' => UploadedFile::fake()->create('huge.png', (int) (CatalogImageStore::MAX_BYTES / 1024) + 1, 'image/png'),
            ])
            ->assertSessionHasErrors('logo');

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('checks again below the form, so a caller that skipped validation cannot write one', function () {
        expect(fn () => app(ManageBrands::class)->create(
            $this->manager,
            ['name' => 'Walton'],
            UploadedFile::fake()->create('brochure.pdf', 40, 'application/pdf'),
        ))->toThrow(CatalogRefused::class, 'cannot be used as a catalogue image');

        expect(Brand::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('removes the logo it replaces', function () {
        $brand = app(ManageBrands::class)->create($this->manager, ['name' => 'Walton'], UploadedFile::fake()->image('old.png'));
        $old = (string) $brand->logo_path;

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.update', $brand->public_id), [
                'name' => 'Walton',
                'logo' => UploadedFile::fake()->image('new.webp'),
            ])
            ->assertSessionHasNoErrors();

        $new = (string) $brand->refresh()->logo_path;

        expect($new)->not->toBe($old)->and($new)->toEndWith('.webp');
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    });

    it('removes a logo when asked, and only when asked', function () {
        $brand = app(ManageBrands::class)->create($this->manager, ['name' => 'Walton'], UploadedFile::fake()->image('logo.png'));
        $path = (string) $brand->logo_path;

        // A multipart form sends "0" for an unticked box that was sent anyway.
        // Read loosely, that string would delete the logo.
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.update', $brand->public_id), ['name' => 'Walton', 'remove_logo' => '0'])
            ->assertSessionHasNoErrors();

        expect($brand->refresh()->logo_path)->toBe($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.update', $brand->public_id), ['name' => 'Walton', 'remove_logo' => '1'])
            ->assertSessionHasNoErrors();

        expect($brand->refresh()->logo_path)->toBeNull();
        Storage::disk('public')->assertMissing($path);
    });

    it('keeps the old logo when a save fails, and discards the new upload', function () {
        catalogBrand(['name' => 'Samsung', 'slug' => 'samsung']);

        $brand = app(ManageBrands::class)->create($this->manager, ['name' => 'Walton'], UploadedFile::fake()->image('old.png'));
        $old = (string) $brand->logo_path;

        // The slug collides at the index, inside the transaction.
        expect(fn () => app(ManageBrands::class)->update(
            $this->manager,
            $brand,
            ['slug' => 'samsung'],
            UploadedFile::fake()->image('new.png'),
        ))->toThrow(UniqueConstraintViolationException::class);

        expect(Brand::query()->whereKey($brand->id)->value('logo_path'))->toBe($old)
            ->and(Storage::disk('public')->allFiles())->toBe([$old]);
    });
});

describe('switching a brand off rather than deleting it (§11.3)', function () {
    it('switches one off and keeps the row', function () {
        $brand = catalogBrand();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.brands.active', $brand->public_id), ['is_active' => false])
            ->assertRedirect();

        expect($brand->refresh()->is_active)->toBeFalse()
            ->and(Brand::query()->count())->toBe(1);
    });

    it('removes a brand together with its logo', function () {
        $brand = app(ManageBrands::class)->create($this->manager, ['name' => 'Walton'], UploadedFile::fake()->image('logo.png'));
        $path = (string) $brand->logo_path;

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.brands.destroy', $brand->public_id))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Brand::query()->count())->toBe(0);
        Storage::disk('public')->assertMissing($path);
    });
});

describe('the screen', function () {
    it('pages on the server', function () {
        foreach (range(1, 30) as $number) {
            catalogBrand(['name' => "Brand {$number}"]);
        }

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.brands.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/brands')
                ->has('brands.data', 25)
                ->where('brands.total', 30)
                ->where('can.create', true),
            );
    });

    it('searches and filters in the database, and lists switched-off brands', function () {
        catalogBrand(['name' => 'Walton']);
        catalogBrand(['name' => 'Samsung']);
        catalogBrand(['name' => 'Singer', 'is_active' => false]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.brands.index', ['search' => 'sam']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('brands.data', 1)
                ->where('brands.data.0.name', 'Samsung'),
            );

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.brands.index', ['status' => 'inactive']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('brands.data', 1)
                ->where('brands.data.0.name', 'Singer')
                ->where('brands.data.0.is_active', false)
                ->where('filters.status', 'inactive'),
            );

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.brands.index'))
            ->assertInertia(fn (Assert $page) => $page->has('brands.data', 3));
    });

    it('ignores a sort column it does not offer', function () {
        catalogBrand();

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.brands.index', ['sort' => 'logo_path; drop table brands', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('brands.data', 1));
    });

    it('tells a read-only viewer they may not write', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->get(route('admin.catalog.brands.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('can.edit', false)
                ->where('can.delete', false),
            );
    });
});
