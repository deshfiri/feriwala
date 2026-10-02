<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Actions\DeleteProductContent;
use App\Domain\Catalog\Actions\PublishProductContent;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductContent;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Storage\Models\StoredFile;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Updates an administrator publishes for a product, time to time (new
 * feature): a title, optional details, and at most one attachment. Every
 * operating partner reads these from the product's own page, through the
 * same gate every other business-side screen already has (§12, D27).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake('public');

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Cotton Panjabi',
        'sku' => 'FW-1043',
        'category_id' => Category::create(['name' => 'Clothing'])->id,
    ]);
});

function productContentPackage(): Package
{
    return Package::create([
        'slug' => 'content-'.Str::lower(Str::random(8)),
        'name' => 'Content package',
        'currency_code' => 'BDT',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'is_active' => true,
        'is_public' => true,
    ]);
}

function productContentOperatingAccount(): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => productContentPackage()->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'currency_code' => 'BDT',
    ]);

    return $account;
}

describe('only the platform publishes content (§12)', function () {
    it('refuses a business account holder, and stores nothing', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Restock notice',
            ])
            ->assertForbidden();

        expect(ProductContent::query()->count())->toBe(0);
    });

    it('refuses staff who may only read the catalogue', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Restock notice',
            ])
            ->assertForbidden();

        expect(ProductContent::query()->count())->toBe(0);
    });

    it('checks again below the form', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        expect(fn () => app(PublishProductContent::class)->handle($owner, $this->product, 'x', null, null, null))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    });
});

describe('publishing', function () {
    it('publishes a title and body with no attachment', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Restocked',
                'body' => 'Back in stock from Monday.',
            ])
            ->assertSessionHasNoErrors();

        $content = ProductContent::query()->firstOrFail();

        expect($content->title)->toBe('Restocked')
            ->and($content->body)->toBe('Back in stock from Monday.')
            ->and($content->created_by)->toBe($this->manager->id)
            ->and($content->published_at)->not->toBeNull()
            ->and($content->attachment)->toBeNull();
    });

    it('stores an image attachment through the shared storage abstraction', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'New packaging',
                'image' => UploadedFile::fake()->image('box.png', 200, 200),
            ])
            ->assertSessionHasNoErrors();

        $content = ProductContent::query()->with('attachment')->firstOrFail();

        expect($content->attachment)->not->toBeNull()
            ->and($content->attachment->mime_type)->toBe('image/png')
            ->and($content->attachment->fileable_type)->toBe(ProductContent::class)
            ->and($content->attachment->fileable_id)->toBe($content->id);

        Storage::disk('public')->assertExists($content->attachment->path);
    });

    it('stores a file attachment', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Spec sheet',
                // `->create()` reports a size and MIME type without writing
                // matching real bytes; the shared storage abstraction reads
                // its checksum and size from the file's own bytes, so the
                // fixture needs real content behind the name it is given.
                'file' => UploadedFile::fake()->createWithContent('spec.pdf', '%PDF-1.4 fake spec sheet'),
            ])
            ->assertSessionHasNoErrors();

        $content = ProductContent::query()->with('attachment')->firstOrFail();

        expect($content->attachment->mime_type)->toBe('application/pdf');
    });

    it('refuses an image and a file at once', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Both at once',
                'image' => UploadedFile::fake()->image('box.png'),
                'file' => UploadedFile::fake()->create('spec.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasErrors();

        expect(ProductContent::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses a blank title', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), ['title' => ''])
            ->assertSessionHasErrors('title');

        expect(ProductContent::query()->count())->toBe(0);
    });

    it('refuses an image over the limit, and leaves nothing behind', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Too big',
                'image' => UploadedFile::fake()->create('huge.png', (int) (PublishProductContent::IMAGE_MAX_BYTES / 1024) + 1, 'image/png'),
            ])
            ->assertSessionHasErrors('image');

        expect(ProductContent::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses a file type outside the accepted lists', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Wrong type',
                'file' => UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4'),
            ])
            ->assertSessionHasErrors('file');

        expect(ProductContent::query()->count())->toBe(0);
    });
});

describe('removing', function () {
    it('removes the row and its attachment together', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.content.store', $this->product->public_id), [
                'title' => 'Restocked',
                'image' => UploadedFile::fake()->image('box.png'),
            ]);

        $content = ProductContent::query()->with('attachment')->firstOrFail();
        $path = $content->attachment->path;

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.content.destroy', [$this->product->public_id, $content->public_id]))
            ->assertSessionHasNoErrors();

        expect(ProductContent::query()->count())->toBe(0)
            ->and(StoredFile::query()->count())->toBe(0);

        Storage::disk('public')->assertMissing($path);
    });

    it('cannot reach another product’s content through this product', function () {
        $other = Product::create(['name' => 'Other', 'sku' => 'FW-9', 'category_id' => $this->product->category_id]);
        $content = app(PublishProductContent::class)->handle($this->manager, $other, 'Other update', null, null, null);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.content.destroy', [$this->product->public_id, $content->public_id]))
            ->assertNotFound();

        expect(ProductContent::query()->count())->toBe(1);
    });

    it('refuses a business account holder', function () {
        $content = app(PublishProductContent::class)->handle($this->manager, $this->product, 'x', null, null, null);
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)
            ->delete(route('admin.catalog.products.content.destroy', [$this->product->public_id, $content->public_id]))
            ->assertForbidden();

        expect(ProductContent::query()->count())->toBe(1);
    });
});

it('shows every update on the admin editor, newest first', function () {
    $older = app(PublishProductContent::class)->handle($this->manager, $this->product, 'Older', null, null, null);
    test()->travel(1)->hours();
    $newer = app(PublishProductContent::class)->handle($this->manager, $this->product, 'Newer', null, null, null);

    $this->actingAs($this->manager)
        ->get(route('admin.catalog.products.edit', $this->product->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('content', 2)
            ->where('content.0.id', $newer->public_id)
            ->where('content.0.title', 'Newer')
            ->where('content.1.id', $older->public_id));
});

it('is visible to an operating partner on the product’s own catalogue page, and removed once deleted', function () {
    $account = productContentOperatingAccount();
    $this->product->forceFill([
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ])->save();

    $content = app(PublishProductContent::class)->handle($this->manager, $this->product, 'Restocked', 'Back from Monday.', null, null);

    $this->actingAs($account->owner)
        ->get(route('catalog.wholesale.show', $this->product->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('content', 1)
            ->where('content.0.id', $content->public_id)
            ->where('content.0.title', 'Restocked'));

    app(DeleteProductContent::class)->handle($this->manager, $content);

    $this->actingAs($account->owner)
        ->get(route('catalog.wholesale.show', $this->product->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('content', []));
});

it('is closed to an account the business.activated gate itself already refuses', function () {
    $account = productContentOperatingAccount();
    $this->product->forceFill([
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ])->save();

    app(PublishProductContent::class)->handle($this->manager, $this->product, 'Restocked', null, null, null);

    $account->forceFill(['status' => AccountStatus::Registered])->save();

    $this->actingAs($account->owner)
        ->get(route('catalog.wholesale.show', $this->product->slug))
        ->assertRedirect();
});
