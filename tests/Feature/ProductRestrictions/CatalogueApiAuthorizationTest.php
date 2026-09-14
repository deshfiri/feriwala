<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ProductAttributeController;
use App\Http\Controllers\Admin\ProductBulkController;
use App\Http\Controllers\Admin\ProductChannelController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductEligibilityController;
use App\Http\Controllers\Admin\ProductMediaController;
use App\Http\Controllers\Admin\ProductMerchandisingController;
use App\Http\Controllers\Admin\ProductPriceTierController;
use App\Http\Controllers\Admin\ProductStatusController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * API authorisation mirroring the same rules (P3-18, §12).
 *
 * The frozen storefront contract gives a storefront `catalog:read` and GET
 * endpoints only (§3.4, §5.1), and those credentials arrive with P5-17. Until
 * then, the callers that are not a browser are JSON clients of these same
 * routes, and they must get the browser's answer: refused before a rule runs,
 * as JSON rather than a page, with nothing written.
 *
 * The routes are read from the router rather than listed here, so a catalogue
 * route added later is covered without anybody remembering to add it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * The controllers that administer the central catalogue.
 *
 * @return array<int, class-string>
 */
function catalogueApiControllers(): array
{
    return [
        ProductController::class,
        ProductBulkController::class,
        ProductStatusController::class,
        ProductChannelController::class,
        ProductEligibilityController::class,
        ProductPriceTierController::class,
        ProductMerchandisingController::class,
        ProductMediaController::class,
        ProductVariantController::class,
        ProductAttributeController::class,
        CategoryController::class,
        BrandController::class,
    ];
}

/**
 * Every route that reaches a catalogue controller, from the router itself.
 *
 * @return array<int, RoutingRoute>
 */
function catalogueApiRoutes(bool $writesOnly = false): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => in_array($route->getControllerClass(), catalogueApiControllers(), true))
        ->reject(fn (RoutingRoute $route) => $writesOnly && catalogueApiWriteMethods($route) === [])
        ->values()
        ->all();
}

/**
 * @return array<int, string>
 */
function catalogueApiWriteMethods(RoutingRoute $route): array
{
    return array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));
}

/**
 * One of everything a catalogue route can address, by the public id it is
 * addressed by.
 *
 * @return array<string, string>
 */
function catalogueApiFixtures(): array
{
    $category = Category::create(['name' => 'Kitchen']);
    $brand = Brand::create(['name' => 'Walton']);
    $product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'base_cost_minor' => 180000,
        'wholesale_price_minor' => 210000,
    ]);
    $attribute = ProductAttribute::create(['name' => 'Size']);
    $value = $attribute->values()->create(['value' => 'Large']);
    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'FW-RC-L', 'combination_key' => 'k']);
    $media = ProductMedia::create([
        'product_id' => $product->id,
        'type' => 'image',
        'disk' => 'public',
        'path' => "catalog/products/{$product->public_id}/1.png",
        'mime_type' => 'image/png',
        'size_bytes' => 100,
        'position' => 1,
    ]);

    return [
        'product' => $product->public_id,
        'variant' => $variant->public_id,
        'media' => $media->public_id,
        'category' => $category->public_id,
        'brand' => $brand->public_id,
        'attribute' => $attribute->public_id,
        'value' => $value->public_id,
        'channel' => 'wholesale',
    ];
}

/**
 * Everything the catalogue holds, as stored — so "nothing was written" is a
 * comparison rather than a guess about which column a refused request might
 * have touched.
 */
function catalogueApiFingerprint(): string
{
    $models = [Product::class, ProductVariant::class, Category::class, Brand::class, ProductAttribute::class, ProductAttributeValue::class, ProductMedia::class, ProductPriceTier::class];

    return md5((string) json_encode(array_map(
        fn (string $model) => $model::query()->orderBy('id')->get()->map->getAttributes()->all(),
        $models,
    )));
}

/**
 * Calls every method of every route as JSON and returns those that did not
 * answer with `$status`.
 *
 * @param  array<int, RoutingRoute>  $routes
 * @param  array<string, string>  $fixtures
 * @return array<int, string>
 */
function catalogueApiUnexpected(mixed $test, array $routes, array $fixtures, int $status, bool $refusal = true): array
{
    $unexpected = [];

    foreach ($routes as $route) {
        $url = route((string) $route->getName(), array_intersect_key($fixtures, array_flip($route->parameterNames())));

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $test->json($method, $url, []);

            $answered = $response->status() === $status
                && str_contains((string) $response->headers->get('Content-Type'), 'json')
                && (! $refusal || ($response->json('message') !== null && $response->json('errors') === null && $response->json('component') === null));

            if (! $answered) {
                $unexpected[] = "{$method} {$route->getName()} answered {$response->status()}";
            }
        }
    }

    return $unexpected;
}

describe('a JSON caller gets the browser\'s refusal, as JSON, before a rule runs', function () {
    it('refuses anybody outside the platform at every catalogue route, reads included', function (Closure $identity) {
        $fixtures = catalogueApiFixtures();
        $before = catalogueApiFingerprint();
        $routes = catalogueApiRoutes();

        expect($routes)->not->toBeEmpty();

        $this->actingAs($identity());

        expect(catalogueApiUnexpected($this, $routes, $fixtures, 403))->toBe([])
            ->and(catalogueApiFingerprint())->toBe($before);
    })->with([
        'a partner' => fn () => testBusinessAccount(AccountStatus::Active)->owner,
        'a partner holding catalogue permissions directly' => fn () => tap(
            testBusinessAccount(AccountStatus::Active)->owner,
            fn (User $owner) => $owner->givePermissionTo(['catalog.view', 'catalog.create', 'catalog.edit', 'catalog.delete', 'catalog.publish', 'catalog.unpublish', 'catalog.archive']),
        ),
        'staff whose role lacks the catalogue' => fn () => testPlatformStaff(PlatformRole::SmsManager),
    ]);

    it('refuses staff who may only view at every catalogue write', function () {
        $fixtures = catalogueApiFixtures();
        $before = catalogueApiFingerprint();
        $routes = catalogueApiRoutes(writesOnly: true);

        expect($routes)->not->toBeEmpty();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager));

        expect(catalogueApiUnexpected($this, $routes, $fixtures, 403))->toBe([])
            ->and(catalogueApiFingerprint())->toBe($before);
    });

    it('answers a caller who is not signed in with 401, never a sign-in redirect', function () {
        $fixtures = catalogueApiFixtures();
        $before = catalogueApiFingerprint();

        expect(catalogueApiUnexpected($this, catalogueApiRoutes(), $fixtures, 401, refusal: false))->toBe([])
            ->and(catalogueApiFingerprint())->toBe($before);
    });
});

describe('no way into the catalogue except the administration', function () {
    it('reaches every catalogue controller only through signed-in administration with a second factor', function () {
        $misplaced = collect(catalogueApiRoutes())
            ->reject(fn (RoutingRoute $route) => str_starts_with((string) $route->getName(), 'admin.catalog.')
                && str_starts_with($route->uri(), 'admin/catalog/')
                && in_array('auth', $route->gatherMiddleware(), true)
                && in_array('two-factor', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        expect($misplaced)->toBe([]);
    });

    it('offers no write to products, categories, brands or attributes anywhere else, and none under api/', function () {
        $catalogueControllers = catalogueApiControllers();

        $elsewhere = collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RoutingRoute $route) => in_array($route->getControllerClass(), $catalogueControllers, true))
            ->filter(fn (RoutingRoute $route) => catalogueApiWriteMethods($route) !== [])
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/')
                || preg_match('/(^|\/)(catalog|catalogue|products?|categories|brands|attributes|variants)(\/|$)/', $route->uri()) === 1)
            ->map(fn (RoutingRoute $route) => implode('|', catalogueApiWriteMethods($route)).' '.$route->uri())
            ->values()
            ->all();

        expect($elsewhere)->toBe([]);
    });

    it('keeps a partner\'s two catalogues read-only', function () {
        $partnerRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => preg_match('/^catalog\.(wholesale|dropshipping)\./', (string) $route->getName()) === 1);

        expect($partnerRoutes)->not->toBeEmpty()
            ->and($partnerRoutes->filter(fn (RoutingRoute $route) => catalogueApiWriteMethods($route) !== [])->all())->toBe([]);
    });
});
