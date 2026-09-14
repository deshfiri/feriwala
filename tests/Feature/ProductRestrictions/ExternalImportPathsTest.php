<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
 * No external product import path, of any kind (P3-20, §12).
 *
 * §12 forbids adding an external product, uploading an unauthorised one and
 * importing external products into the ERP. None of those paths exists, and this
 * file is what keeps it that way: the catalogue fetches nothing from outside,
 * no command or scheduled task writes it, no route is an import, sync or feed,
 * and the endpoints that do write it take one product at a time and refuse a
 * file or a batch posted where it does not belong — by name, rather than
 * ignoring it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Kitchen']);
    $this->product = Product::create(['name' => 'Rice cooker', 'sku' => 'FW-RC', 'category_id' => $this->category->id]);
});

/**
 * A spreadsheet of products, exactly what an import would post.
 */
function importPathsSpreadsheet(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('products.csv', "sku,name,wholesale_price_minor\nEXT-1,Imported kettle,1000\n");
}

/**
 * A valid product form submission, overridable field by field.
 *
 * @return array<string, mixed>
 */
function importPathsProductPayload(array $overrides = []): array
{
    return [
        'name' => 'Kettle',
        'sku' => 'FW-KT',
        'category_id' => Category::query()->value('public_id'),
        'base_cost_minor' => 100000,
        'wholesale_price_minor' => 120000,
        ...$overrides,
    ];
}

arch('the catalogue never fetches anything from outside the application')
    ->expect(['App\Domain\Catalog', 'App\Http\Controllers\Admin\Product', 'App\Http\Requests\Catalog'])
    ->not->toUse([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client\Factory',
        'Illuminate\Http\Client\PendingRequest',
        'GuzzleHttp\Client',
        'curl_init',
        'fsockopen',
    ]);

describe('nothing outside a signed-in form writes the catalogue', function () {
    it('has no console command or scheduled task that reaches it', function () {
        $applicationCommands = collect(Artisan::all())
            ->filter(fn ($command) => str_starts_with($command::class, 'App\\'))
            ->filter(fn ($command) => str_contains((string) file_get_contents((string) (new ReflectionClass($command))->getFileName()), 'Domain\\Catalog'))
            ->keys()
            ->all();

        expect($applicationCommands)->toBe([])
            ->and(file_get_contents(base_path('routes/console.php')))->not->toContain('Domain\\Catalog');
    });

    it('has no route that imports, syncs, feeds or scrapes into anything', function () {
        $importRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [])
            ->filter(fn (RoutingRoute $route) => preg_match('/import|sync|feed|scrape|crawl/i', $route->uri().' '.$route->getName()) === 1)
            ->map(fn (RoutingRoute $route) => $route->uri())
            ->values()
            ->all();

        expect($importRoutes)->toBe([]);
    });
});

describe('one product at a time, never a file or a batch', function () {
    it('refuses a spreadsheet posted to the product form, by name, and creates nothing', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), importPathsProductPayload(['file' => importPathsSpreadsheet()]))
            ->assertSessionHasErrors(['file' => __('catalog.restrictions.file')]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), importPathsProductPayload([
                'sku' => 'FW-RC',
                'catalogue' => [importPathsSpreadsheet()],
            ]))
            ->assertSessionHasErrors('catalogue');

        expect(Product::query()->count())->toBe(1)
            ->and($this->product->refresh()->name)->toBe('Rice cooker');
    });

    it('refuses a list of products posted to the product form, and creates none of them', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), importPathsProductPayload([
                'products' => [
                    ['name' => 'Imported kettle', 'sku' => 'EXT-1'],
                    ['name' => 'Imported toaster', 'sku' => 'EXT-2'],
                ],
            ]))
            ->assertSessionHasErrors(['products' => __('catalog.restrictions.batch')]);

        expect(Product::query()->count())->toBe(1);
    });

    it('refuses a file on a variation, and a batch on quantity pricing', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FW-RC-L',
                'values' => ['x'],
                'sheet' => importPathsSpreadsheet(),
            ])
            ->assertSessionHasErrors('sheet');

        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.price-tiers.update', $this->product->public_id), [
                'tiers' => [],
                'products' => [$this->product->public_id],
            ])
            ->assertSessionHasErrors('products');

        expect(ProductVariant::query()->count())->toBe(0);
    });

    it('lets a bulk action name products that already exist, and never brings one into being', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$this->product->public_id, '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
                'action' => 'feature',
                'enable' => true,
            ])
            ->assertSessionHasNoErrors();

        expect(Product::query()->count())->toBe(1);
    });
});

describe('a product\'s media is an image or video uploaded here', function () {
    it('refuses a spreadsheet as media, reading the file rather than its name', function () {
        Storage::fake('public');

        /*
         * A real file rather than `UploadedFile::fake()`, whose type is guessed
         * from the extension — which is exactly the claim being tested.
         */
        $path = (string) tempnam(sys_get_temp_dir(), 'import');
        file_put_contents($path, "sku,name\nEXT-1,Imported kettle\n");

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'file' => new UploadedFile($path, 'front.png', null, null, true),
            ])
            ->assertSessionHasErrors('file');

        @unlink($path);

        expect(ProductMedia::query()->count())->toBe(0);
    });

    it('never fetches media from an address, and adds nothing without an upload', function () {
        Http::preventStrayRequests();
        Storage::fake('public');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $this->product->public_id), [
                'url' => 'https://images.example.com/kettle.png',
            ])
            ->assertSessionHasErrors('file');

        expect(ProductMedia::query()->count())->toBe(0);
        Http::assertNothingSent();
    });
});
