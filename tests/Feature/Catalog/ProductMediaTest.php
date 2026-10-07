<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageProductMedia;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductMediaStore;
use App\Http\Controllers\Admin\ProductController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Product images and videos (P3-5, §11.1, §12).
 *
 * Uploads are somebody else's browser's choice, so the type is read from the
 * bytes, the size is bounded by whichever is smaller of our cap and PHP's own,
 * and the check runs again below the form. Positions stay a clean 1..n sequence,
 * and a file on disk always has a row pointing at it.
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

function catalogMediaUpload(Product $product, string $name = 'front.png'): ProductMedia
{
    /** @var User $actor */
    $actor = test()->manager;

    return app(ManageProductMedia::class)->add($actor, $product, UploadedFile::fake()->image($name, 400, 300));
}

describe('only the platform adds product media (§12)', function () {
    it('refuses a business account holder, and stores none of their file', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;
        $media = catalogMediaUpload($this->product);

        $this->actingAs($owner)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->image('mine.png'),
            ])
            ->assertForbidden();
        $this->actingAs($owner)
            ->patch(route('admin.catalog.products.media.update', [$this->product->public_id, $media->public_id]), ['alt_text' => 'x'])
            ->assertForbidden();
        $this->actingAs($owner)
            ->post(route('admin.catalog.products.media.reorder', $this->product->public_id), ['order' => [$media->public_id]])
            ->assertForbidden();
        $this->actingAs($owner)
            ->delete(route('admin.catalog.products.media.destroy', [$this->product->public_id, $media->public_id]))
            ->assertForbidden();

        expect(ProductMedia::query()->count())->toBe(1)
            ->and(Storage::disk('public')->allFiles())->toBe([$media->path]);
    });

    it('refuses staff who may only read the catalogue', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->image('front.png'),
            ])
            ->assertForbidden();

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });
});

describe('uploading', function () {
    it('stores an image with its dimensions, under the product, in position order', function () {
        $url = route('admin.catalog.products.media.store', $this->product->public_id);

        $this->actingAs($this->manager)
            ->post($url, ['file' => UploadedFile::fake()->image('Front View.png', 400, 300), 'alt_text' => 'Front'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->manager)
            ->post($url, ['file' => UploadedFile::fake()->image('back.jpg', 400, 300)])
            ->assertSessionHasNoErrors();

        [$first, $second] = $this->product->media()->get()->all();

        expect($first->type)->toBe('image')
            ->and($first->position)->toBe(1)
            ->and($first->width)->toBe(400)
            ->and($first->height)->toBe(300)
            ->and($first->alt_text)->toBe('Front')
            ->and($first->path)->toMatch('#^catalog/products/'.$this->product->public_id.'/[0-9a-f]{32}\.png$#')
            ->and($second->position)->toBe(2);

        Storage::disk('public')->assertExists($first->path);
    });

    it('stores a video', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->create('clip.mp4', 200, 'video/mp4'),
            ])
            ->assertSessionHasNoErrors();

        $media = ProductMedia::query()->firstOrFail();

        expect($media->type)->toBe('video')
            ->and($media->width)->toBeNull()
            ->and($media->path)->toEndWith('.mp4');
    });

    it('refuses a document, an SVG, and HTML renamed as an image — and writes none of them', function () {
        $url = route('admin.catalog.products.media.store', $this->product->public_id);

        $path = tempnam(sys_get_temp_dir(), 'media');
        file_put_contents($path, '<!doctype html><html><body><script>alert(1)</script></body></html>');

        foreach ([
            UploadedFile::fake()->create('brochure.pdf', 40, 'application/pdf'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            new UploadedFile($path, 'front.png', 'image/png', null, true),
        ] as $file) {
            $this->actingAs($this->manager)->post($url, ['file' => $file])->assertSessionHasErrors('file');
        }

        expect(ProductMedia::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses an image over the limit', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->create('huge.png', (int) (ProductMediaStore::IMAGE_MAX_BYTES / 1024) + 1, 'image/png'),
            ])
            ->assertSessionHasErrors('file');

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('never promises more than PHP itself will accept', function () {
        // A cap above `upload_max_filesize` is an upload that fails before
        // validation sees it, with nothing on the screen to say why.
        foreach (['image', 'video'] as $type) {
            expect(ProductMediaStore::maxBytesFor($type))
                ->toBeLessThanOrEqual(ProductMediaStore::serverUploadLimit());
        }

        expect(ProductMediaStore::maxBytesFor('video'))->toBeLessThanOrEqual(ProductMediaStore::VIDEO_MAX_BYTES)
            ->and(ProductMediaStore::maxBytesFor('image'))->toBeLessThanOrEqual(ProductMediaStore::IMAGE_MAX_BYTES);
    });

    it('checks again below the form', function () {
        expect(fn () => app(ManageProductMedia::class)->add(
            $this->manager,
            $this->product,
            UploadedFile::fake()->create('brochure.pdf', 40, 'application/pdf'),
        ))->toThrow(CatalogRefused::class, 'cannot be used as product media');

        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses a variation that belongs to another product', function () {
        $other = Product::create(['name' => 'Other', 'sku' => 'FW-9', 'category_id' => $this->product->category_id]);
        $foreign = ProductVariant::create(['product_id' => $other->id, 'sku' => 'FW-9-M', 'combination_key' => 'k']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->image('front.png'),
                'variant_id' => $foreign->public_id,
            ])
            ->assertSessionHasErrors('variant_id');

        // The upload was written before the refusal and cleaned up after it.
        expect(Storage::disk('public')->allFiles())->toBe([]);
    });

    it('refuses a file beyond the per-product cap, and leaves nothing behind', function () {
        foreach (range(1, ManageProductMedia::MAX_PER_PRODUCT) as $position) {
            ProductMedia::create([
                'product_id' => $this->product->id,
                'type' => 'image',
                'disk' => 'public',
                'path' => "catalog/products/x/{$position}.png",
                'mime_type' => 'image/png',
                'size_bytes' => 10,
                'position' => $position,
            ]);
        }

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => UploadedFile::fake()->image('one-too-many.png'),
            ])
            ->assertSessionHasErrors('file');

        expect(ProductMedia::query()->count())->toBe(ManageProductMedia::MAX_PER_PRODUCT)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });
});

describe('describing, ordering and removing', function () {
    beforeEach(function () {
        $this->a = catalogMediaUpload($this->product, 'a.png');
        $this->b = catalogMediaUpload($this->product, 'b.png');
        $this->c = catalogMediaUpload($this->product, 'c.png');
    });

    it('puts the media in the order given, ignoring ids that are not this product’s', function () {
        $other = Product::create(['name' => 'Other', 'sku' => 'FW-9', 'category_id' => $this->product->category_id]);
        $foreign = catalogMediaUpload($other);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.reorder', $this->product->public_id), [
                'order' => [$this->c->public_id, $foreign->public_id, $this->a->public_id],
            ])
            ->assertSessionHasNoErrors();

        // Unmentioned files keep their place after the mentioned ones.
        expect($this->product->media()->pluck('public_id')->all())
            ->toBe([$this->c->public_id, $this->a->public_id, $this->b->public_id])
            ->and($this->product->media()->pluck('position')->all())->toBe([1, 2, 3])
            ->and($foreign->refresh()->position)->toBe(1);
    });

    it('changes alt text and the variation a file shows', function () {
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-1043-M', 'combination_key' => 'k']);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.media.update', [$this->product->public_id, $this->b->public_id]), [
                'alt_text' => 'Navy, back view',
                'variant_id' => $variant->public_id,
            ])
            ->assertSessionHasNoErrors();

        expect($this->b->refresh()->alt_text)->toBe('Navy, back view')
            ->and($this->b->product_variant_id)->toBe($variant->id);
    });

    it('cannot reach another product’s file through this product', function () {
        $other = Product::create(['name' => 'Other', 'sku' => 'FW-9', 'category_id' => $this->product->category_id]);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.media.destroy', [$other->public_id, $this->a->public_id]))
            ->assertNotFound();

        expect(ProductMedia::query()->count())->toBe(3);
    });

    it('removes a file and closes the gap it leaves', function () {
        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.media.destroy', [$this->product->public_id, $this->a->public_id]))
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($this->a->path);

        expect($this->product->media()->pluck('public_id')->all())->toBe([$this->b->public_id, $this->c->public_id])
            ->and($this->product->media()->pluck('position')->all())->toBe([1, 2]);
    });

    it('shows the media on the editor with the limits the server enforces', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('media', 3)
                ->where('media.0.id', $this->a->public_id)
                ->where('media.0.type', 'image')
                ->where('media.0.position', 1)
                ->where('media.0.url', fn (string $url) => str_contains($url, $this->a->path))
                ->where('media_limits.max_items', ManageProductMedia::MAX_PER_PRODUCT)
                ->where('media_limits.image_max_mb', ProductController::megabytes(ProductMediaStore::maxBytesFor('image'))),
            );
    });

    it('goes with its permanently deleted product, explicitly, files included', function () {
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-1043-M', 'combination_key' => 'k']);

        app(ManageProducts::class)->trash($this->manager, $this->product, 'Test.');
        app(ManageProducts::class)->permanentlyDelete($this->manager, $this->product->refresh());

        expect(Product::withTrashed()->count())->toBe(0)
            ->and(ProductMedia::query()->count())->toBe(0)
            ->and(ProductVariant::query()->count())->toBe(0)
            ->and(Storage::disk('public')->allFiles())->toBe([]);
    });
});
