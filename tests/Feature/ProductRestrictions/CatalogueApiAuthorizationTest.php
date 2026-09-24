<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
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
use App\Http\Controllers\Api\Storefront\V1\CustomerController as StorefrontCustomerController;
use App\Http\Controllers\Api\Storefront\V1\OrderController as StorefrontOrderController;
use App\Http\Controllers\Api\Storefront\V1\ReturnController as StorefrontReturnController;
use App\Http\Controllers\Erp\WebsiteCategoryController;
use App\Http\Controllers\Erp\WebsiteProductController;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
 * Website routes whose addresses say "products" or "categories" but which write
 * only a partner's own website copy: a selection of a central product, or the
 * website's own arrangement of its selections (P5-2, P5-4, P5-14).
 *
 * Named one by one with the controller each must stay on, so a new route, or
 * one of these pointed somewhere else, still trips the check below. What they
 * write is proved beside it: the catalogue is exactly as it was afterwards.
 *
 * @return array<string, class-string>
 */
function catalogueApiWebsiteCopyRoutes(): array
{
    return [
        'websites.products.store' => WebsiteProductController::class,
        'websites.products.update' => WebsiteProductController::class,
        'websites.products.publication.update' => WebsiteProductController::class,
        'websites.products.destroy' => WebsiteProductController::class,
        'websites.categories.store' => WebsiteCategoryController::class,
        'websites.categories.reorder' => WebsiteCategoryController::class,
        'websites.categories.update' => WebsiteCategoryController::class,
        'websites.categories.destroy' => WebsiteCategoryController::class,
    ];
}

/**
 * The writes the storefront contract allows under `api/`, named one by one with
 * the controller that serves them (contract §6.1, §6.2, §6.3).
 *
 * Orders, their confirmation and returns, and customers — nothing else: no
 * product, category, brand or attribute is writable through this surface,
 * which is what the test below proves by fingerprinting the catalogue around a
 * submitted order.
 *
 * @return array<string, class-string>
 */
function catalogueApiStorefrontWriteRoutes(): array
{
    return [
        'storefront.v1.orders.store' => StorefrontOrderController::class,
        'storefront.v1.orders.payment-session' => StorefrontOrderController::class,
        'storefront.v1.orders.cancel' => StorefrontOrderController::class,
        'storefront.v1.orders.confirmation.code' => StorefrontOrderController::class,
        'storefront.v1.orders.confirmation.store' => StorefrontOrderController::class,
        'storefront.v1.orders.return-requests.store' => StorefrontReturnController::class,
        'storefront.v1.customers.store' => StorefrontCustomerController::class,
        'storefront.v1.customers.update' => StorefrontCustomerController::class,
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
        'base_cost' => Money::fromDecimal('1800.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2100.00', Currency::BDT),
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
            ->reject(fn (RoutingRoute $route) => ! str_starts_with($route->uri(), 'api/')
                && (catalogueApiWebsiteCopyRoutes()[(string) $route->getName()] ?? null) === $route->getControllerClass())
            // The storefront's own writes, by name and controller: orders and
            // customers, never the catalogue (contract §6.1, §6.2).
            ->reject(fn (RoutingRoute $route) => (catalogueApiStorefrontWriteRoutes()[(string) $route->getName()] ?? null) === $route->getControllerClass())
            ->filter(fn (RoutingRoute $route) => catalogueApiWriteMethods($route) !== [])
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/')
                || preg_match('/(^|\/)(catalog|catalogue|products?|categories|brands|attributes|variants)(\/|$)/', $route->uri()) === 1)
            ->map(fn (RoutingRoute $route) => implode('|', catalogueApiWriteMethods($route)).' '.$route->uri())
            ->values()
            ->all();

        expect($elsewhere)->toBe([]);
    });

    it('leaves the catalogue exactly as it was when a partner arranges their website', function () {
        $account = websiteTestAccount(extra: [
            PackageFeature::DropshippingEnabled->value => '1',
            PackageFeature::ProductPublishLimit->value => null,
        ]);
        $website = Website::factory()->forAccount($account)->active()->create();
        $product = websiteTestProduct();
        $before = catalogueApiFingerprint();

        $this->actingAs($account->owner);

        // A full product payload at the selection endpoint selects; it creates nothing.
        $this->post(route('websites.products.store', $website->public_id), [
            'product' => $product->public_id,
            'name' => 'My own product',
            'sku' => 'MINE-0001',
            'wholesale_price' => '1.00',
        ])->assertSessionHasNoErrors();

        $selection = WebsiteProduct::query()->where('website_id', $website->id)->firstOrFail();
        $pair = [$website->public_id, $selection->public_id];

        $this->patch(route('websites.products.update', $pair), ['price' => '2600.00', 'marketing_description' => 'Eid favourite'])->assertSessionHasNoErrors();
        $this->put(route('websites.products.publication.update', $pair), ['published' => true])->assertSessionHasNoErrors();
        $this->put(route('websites.products.publication.update', $pair), ['published' => false])->assertSessionHasNoErrors();

        $this->post(route('websites.categories.store', $website->public_id), ['name' => 'Eid'])->assertSessionHasNoErrors();
        $category = WebsiteCategory::query()->where('website_id', $website->id)->firstOrFail();
        $categoryPair = [$website->public_id, $category->public_id];

        $this->patch(route('websites.categories.update', $categoryPair), ['name' => 'Eid sale'])->assertSessionHasNoErrors();
        $this->post(route('websites.categories.reorder', $website->public_id), ['order' => [$category->public_id]])->assertSessionHasNoErrors();
        $this->delete(route('websites.categories.destroy', $categoryPair))->assertSessionHasNoErrors();
        $this->delete(route('websites.products.destroy', $pair))->assertSessionHasNoErrors();

        expect(WebsiteProduct::query()->count())->toBe(0)
            ->and(catalogueApiFingerprint())->toBe($before);
    });

    it('leaves the catalogue exactly as it was when a website takes an order', function () {
        $account = websiteTestAccount(extra: [
            PackageFeature::DropshippingEnabled->value => '1',
            PackageFeature::ProductPublishLimit->value => null,
        ]);

        $website = Website::factory()->forAccount($account)->active()->create();
        $product = websiteTestProduct();

        WebsiteProduct::create([
            'website_id' => $website->id,
            'business_account_id' => $account->id,
            'product_id' => $product->id,
            'status' => WebsiteProductStatus::Published,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price' => Money::fromDecimal('2600.00', Currency::BDT),
            'published_at' => now(),
        ]);

        $warehouse = Warehouse::query()->firstOrCreate(['code' => 'DHK'], ['name' => 'Dhaka', 'is_default' => true]);
        $item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id]);
        app(StockLedger::class)->move($item, null, StockBucket::Available, 20, StockMovementType::Adjustment);

        FeeRule::create([
            'fee_type' => FeeType::WebsiteDelivery->value,
            'amount' => Money::fromDecimal('60.00', Currency::BDT),
            'currency_code' => 'BDT',
            'effective_from' => now()->subDay(),
        ]);

        // A gateway to open the payment with; the order cannot be taken without one.
        $settings = app(SettingsRepository::class);
        $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
        $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
        $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

        $before = catalogueApiFingerprint();
        $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);

        $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

        [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
            reference: 'SF-CATALOGUE-1',
            idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+8801712345678'),
            shippingAddress: $address,
            billingAddress: $address,
            items: [['sku' => $product->sku, 'quantity' => 2]],
            claimedUnitPrices: [$money('2600.00')],
            claimedTotals: [
                'subtotal' => $money('5200.00'),
                'discount' => $money('0.00'),
                'shipping' => $money('60.00'),
                'tax' => $money('0.00'),
                'grand_total' => $money('5260.00'),
            ],
            paymentMethod: 'online',
        ));

        // The order is a snapshot of what was bought; the catalogue it was
        // bought from is not touched by buying from it (§12, D12).
        expect($order->items()->sole()->sku)->toBe($product->sku)
            ->and(catalogueApiFingerprint())->toBe($before);
    });

    it('keeps a partner\'s two catalogues read-only', function () {
        $partnerRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => preg_match('/^catalog\.(wholesale|dropshipping)\./', (string) $route->getName()) === 1);

        expect($partnerRoutes)->not->toBeEmpty()
            ->and($partnerRoutes->filter(fn (RoutingRoute $route) => catalogueApiWriteMethods($route) !== [])->all())->toBe([]);
    });
});
