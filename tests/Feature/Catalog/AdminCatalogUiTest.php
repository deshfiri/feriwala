<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\GenerateVariants;
use App\Domain\Catalog\Actions\ManageVariants;
use App\Domain\Catalog\Actions\SetFeatured;
use App\Domain\Catalog\Actions\SetSalesChannel;
use App\Domain\Catalog\Actions\TransitionProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SalesChannel;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductStatusChange;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The catalogue administration screens (P3-13, §11, §12).
 *
 * The list filters and sorts in the database and offers bulk actions that go
 * through the same actions as one product's editor, so every product is checked
 * for its own move and refused for its own reasons. The variation builder
 * creates every new combination in one all-or-nothing request. None of it is
 * reachable by a business account or by staff without the permission.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->admin = testPlatformStaff(PlatformRole::Admin);

    $this->kitchen = Category::create(['name' => 'Kitchen']);
    $this->cookware = Category::create(['name' => 'Cookware', 'parent_id' => $this->kitchen->id]);
    $this->garden = Category::create(['name' => 'Garden']);

    $this->walton = Brand::create(['name' => 'Walton']);
    $this->vision = Brand::create(['name' => 'Vision']);
});

function catalogUiProduct(string $sku, Category $category, array $overrides = []): Product
{
    return Product::create([
        'name' => 'Product '.$sku,
        'sku' => $sku,
        'category_id' => $category->id,
        'base_cost' => Money::fromDecimal('1700.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2100.00', Currency::BDT),
        ...$overrides,
    ]);
}

function catalogUiProps(TestResponse $response, string $key): mixed
{
    return data_get($response->assertOk()->viewData('page'), 'props.'.$key);
}

function catalogUiMove(User $actor, Product $product, ProductStatus ...$moves): Product
{
    foreach ($moves as $to) {
        app(TransitionProduct::class)->handle($actor, $product, $to, $to->requiresReason() ? 'Test' : null);
    }

    return $product->refresh();
}

function catalogUiAttribute(string $name, array $values, int $sortOrder): ProductAttribute
{
    $attribute = ProductAttribute::create(['name' => $name, 'sort_order' => $sortOrder]);

    foreach ($values as $position => $value) {
        ProductAttributeValue::create([
            'product_attribute_id' => $attribute->id,
            'value' => $value,
            'sort_order' => $position,
        ]);
    }

    return $attribute;
}

/**
 * @return array<int, string>
 */
function catalogUiValues(ProductAttribute $attribute, array $values): array
{
    return $attribute->values()->whereIn('value', $values)->pluck('public_id')->all();
}

describe('the product list', function () {
    beforeEach(function () {
        $this->toaster = catalogUiProduct('FW-A', $this->kitchen, ['brand_id' => $this->walton->id]);
        $this->pan = catalogUiProduct('FW-B', $this->cookware, ['brand_id' => $this->vision->id]);
        $this->hose = catalogUiProduct('FW-C', $this->garden);

        app(SetSalesChannel::class)->handle($this->manager, $this->toaster, SalesChannel::Wholesale, true);
        app(SetFeatured::class)->handle($this->manager, $this->toaster, true);
        catalogUiMove($this->manager, $this->pan, ProductStatus::PendingReview);
    });

    it('filters by status, category with its subcategories, brand, channel and featured', function () {
        $skus = fn (array $query) => collect(catalogUiProps(
            $this->actingAs($this->manager)
                ->get(route('admin.catalog.products.index', [...$query, 'sort' => 'sku', 'direction' => 'asc'])),
            'products.data',
        ))->pluck('sku')->all();

        expect($skus(['category' => $this->kitchen->public_id]))->toBe(['FW-A', 'FW-B'])
            ->and($skus(['brand' => $this->vision->public_id]))->toBe(['FW-B'])
            ->and($skus(['status' => 'pending_review']))->toBe(['FW-B'])
            ->and($skus(['channel' => 'wholesale_enabled']))->toBe(['FW-A'])
            ->and($skus(['channel' => 'wholesale_disabled']))->toBe(['FW-B', 'FW-C'])
            ->and($skus(['featured' => 'yes']))->toBe(['FW-A'])
            ->and($skus(['featured' => 'no', 'category' => $this->kitchen->public_id]))->toBe(['FW-B']);
    });

    it('ignores filter and sort values it does not know', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.index', [
                'status' => 'published',
                'channel' => 'status',
                'featured' => 'maybe',
                'category' => "x' OR 1=1",
                'sort' => 'base_cost_minor',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('products.data', 3)
                ->where('filters', [
                    'status' => null,
                    'category' => null,
                    'brand' => null,
                    'channel' => null,
                    'featured' => null,
                ]),
            );
    });

    it('sorts by the columns it allows', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.index', ['sort' => 'sku', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.sku', 'FW-C'));

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.sku', 'FW-A'));
    });

    it('shows the listing image, both channels and how much hangs off each product', function () {
        foreach ([1, 2] as $position) {
            ProductMedia::create([
                'product_id' => $this->toaster->id,
                'type' => 'image',
                'disk' => 'public',
                'path' => "catalog/products/{$this->toaster->public_id}/{$position}.png",
                'mime_type' => 'image/png',
                'size_bytes' => 100,
                'position' => $position,
            ]);
        }

        ProductVariant::create(['product_id' => $this->toaster->id, 'sku' => 'FW-A-1', 'combination_key' => 'x']);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.index', ['featured' => 'yes']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.data.0.image_url', fn (string $url) => str_ends_with($url, '/1.png'))
                ->where('products.data.0.media_count', 2)
                ->where('products.data.0.variants_count', 1)
                ->where('products.data.0.channels.0.enabled', false)
                ->where('products.data.0.channels.1.status', 'wholesale_enabled')
                ->where('products.data.0.is_featured', true),
            );
    });

    it('offers each person only the bulk actions their permissions reach', function () {
        $targets = fn (User $user) => collect(catalogUiProps(
            $this->actingAs($user)->get(route('admin.catalog.products.index')),
            'bulk.transitions',
        ))->pluck('value')->all();

        expect($targets($this->manager))->toContain('active', 'archived', 'inactive')
            ->and($targets($this->admin))->toContain('pending_review', 'draft', 'discontinued')
            ->and($targets($this->admin))->not->toContain('active', 'archived', 'inactive')
            ->and($targets(testPlatformStaff(PlatformRole::InventoryManager)))->toBe([]);

        $this->actingAs($this->admin)
            ->get(route('admin.catalog.products.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bulk.enable_channels', false)
                ->where('bulk.feature', false)
                ->where('bulk.max', 100),
            );
    });
});

describe('bulk actions', function () {
    it('activates what is ready, names what is not with its reason, and counts what already was', function () {
        $ready = catalogUiMove($this->manager, catalogUiProduct('FW-1', $this->kitchen), ProductStatus::PendingReview);
        $unpriced = catalogUiMove($this->manager, catalogUiProduct('FW-2', $this->kitchen, ['wholesale_price' => Money::zero(Currency::BDT)]), ProductStatus::PendingReview);
        $live = catalogUiMove($this->manager, catalogUiProduct('FW-3', $this->kitchen), ProductStatus::PendingReview, ProductStatus::Active);

        $this->actingAs($this->manager)
            ->from(route('admin.catalog.products.index'))
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$ready->public_id, $unpriced->public_id, $live->public_id],
                'action' => 'transition',
                'status' => 'active',
            ])
            ->assertRedirect(route('admin.catalog.products.index'))
            ->assertInertiaFlash('bulk_result.changed', 1)
            ->assertInertiaFlash('bulk_result.unchanged', 1)
            ->assertInertiaFlash('bulk_result.refused.0.sku', 'FW-2')
            ->assertInertiaFlash('bulk_result.refused.0.reason', 'This product cannot be activated yet: it has no wholesale price.')
            ->assertInertiaFlash('toast.type', 'warning');

        expect($ready->refresh()->status)->toBe(ProductStatus::Active)
            ->and($unpriced->refresh()->status)->toBe(ProductStatus::PendingReview)
            ->and(ProductStatusChange::query()->where('product_id', $ready->id)->where('to_status', 'active')->count())->toBe(1);
    });

    it('refuses a target nobody with these permissions could reach, and checks each product for its own move', function () {
        $pending = catalogUiMove($this->manager, catalogUiProduct('FW-1', $this->kitchen), ProductStatus::PendingReview);

        $this->actingAs($this->admin)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$pending->public_id],
                'action' => 'transition',
                'status' => 'active',
            ])
            ->assertForbidden();

        expect($pending->refresh()->status)->toBe(ProductStatus::PendingReview);

        // Discontinuing an inactive product is authoring; a live one, taking it off sale.
        $inactive = catalogUiMove($this->manager, catalogUiProduct('FW-2', $this->kitchen), ProductStatus::PendingReview, ProductStatus::Active, ProductStatus::Inactive);
        $live = catalogUiMove($this->manager, catalogUiProduct('FW-3', $this->kitchen), ProductStatus::PendingReview, ProductStatus::Active);

        $this->actingAs($this->admin)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$inactive->public_id, $live->public_id],
                'action' => 'transition',
                'status' => 'discontinued',
                'reason' => 'Supplier stopped making it',
            ])
            ->assertInertiaFlash('bulk_result.changed', 1)
            ->assertInertiaFlash('bulk_result.refused.0.sku', 'FW-3')
            ->assertInertiaFlash('bulk_result.refused.0.reason', 'You may not move a product from Active to Discontinued.');

        expect($inactive->refresh()->status)->toBe(ProductStatus::Discontinued)
            ->and($live->refresh()->status)->toBe(ProductStatus::Active);
    });

    it('asks for a reason when the move retires products', function () {
        $draft = catalogUiProduct('FW-1', $this->kitchen);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$draft->public_id],
                'action' => 'transition',
                'status' => 'archived',
            ])
            ->assertSessionHasErrors('reason');

        expect($draft->refresh()->status)->toBe(ProductStatus::Draft);
    });

    it('switches a channel in bulk, only for someone who may publish', function () {
        $first = catalogUiProduct('FW-1', $this->kitchen);
        $second = catalogUiProduct('FW-2', $this->kitchen);
        $payload = ['products' => [$first->public_id, $second->public_id], 'action' => 'channel', 'channel' => 'dropshipping', 'enable' => true];

        $this->actingAs($this->admin)->post(route('admin.catalog.products.bulk'), $payload)->assertForbidden();

        expect($first->refresh()->sellsThrough(SalesChannel::Dropshipping))->toBeFalse();

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), $payload)
            ->assertInertiaFlash('bulk_result.changed', 2)
            ->assertInertiaFlash('toast.type', 'success');

        expect($first->refresh()->sellsThrough(SalesChannel::Dropshipping))->toBeTrue()
            ->and(ProductStatusChange::query()->where('axis', 'dropshipping')->count())->toBe(2);
    });

    it('features in bulk, and repeating the request changes nothing', function () {
        $first = catalogUiProduct('FW-1', $this->kitchen);
        $second = catalogUiProduct('FW-2', $this->kitchen);
        $payload = ['products' => [$first->public_id, $second->public_id], 'action' => 'feature', 'enable' => true];

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), $payload)
            ->assertInertiaFlash('bulk_result.changed', 2);

        $featuredAt = $first->refresh()->featured_at;

        $this->travel(5)->minutes();

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), $payload)
            ->assertInertiaFlash('bulk_result.changed', 0)
            ->assertInertiaFlash('bulk_result.unchanged', 2);

        expect($first->refresh()->featured_at?->equalTo($featuredAt))->toBeTrue();
    });

    it('is refused to a business account and to view-only staff before any rule runs (§12)', function () {
        $product = catalogUiProduct('FW-1', $this->kitchen);

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->post(route('admin.catalog.products.bulk'), [])
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.products.bulk'), ['products' => [$product->public_id], 'action' => 'feature', 'enable' => true])
            ->assertForbidden();

        expect($product->refresh()->is_featured)->toBeFalse();
    });

    it('names a product that no longer exists, and holds a request to its limit', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), ['products' => ['01jzzzzzzzzzzzzzzzzzzzzzzz'], 'action' => 'feature', 'enable' => true])
            ->assertInertiaFlash('bulk_result.refused.0.name', null)
            ->assertInertiaFlash('bulk_result.refused.0.reason', 'This product no longer exists.');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => array_map(fn (int $index) => 'id'.$index, range(1, 101)),
                'action' => 'feature',
                'enable' => true,
            ])
            ->assertSessionHasErrors('products');
    });
});

describe('the variation builder', function () {
    beforeEach(function () {
        $this->product = catalogUiProduct('FW-1043', $this->kitchen);
        $this->size = catalogUiAttribute('Size', ['M', 'L'], 0);
        $this->colour = catalogUiAttribute('Colour', ['Navy', 'Red'], 1);
    });

    it('creates every combination, with SKUs made from the product’s and the product’s own prices', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.generate', $this->product->public_id), [
                'values' => [...catalogUiValues($this->size, ['M', 'L']), ...catalogUiValues($this->colour, ['Navy', 'Red'])],
            ])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $variants = $this->product->variants()->get();

        expect($variants->pluck('sku')->sort()->values()->all())->toBe(['FW-1043-L-NAVY', 'FW-1043-L-RED', 'FW-1043-M-NAVY', 'FW-1043-M-RED'])
            ->and($variants->every(fn (ProductVariant $variant) => $variant->wholesale_price === null && $variant->is_active))->toBeTrue();
    });

    it('skips combinations that exist, so building again creates only what is new', function () {
        app(ManageVariants::class)->create($this->manager, $this->product, [
            'sku' => 'MY-OWN-SKU',
            'values' => [...catalogUiValues($this->size, ['M']), ...catalogUiValues($this->colour, ['Navy'])],
        ]);

        $values = [...catalogUiValues($this->size, ['M', 'L']), ...catalogUiValues($this->colour, ['Navy'])];

        $built = app(GenerateVariants::class)->handle($this->manager, $this->product, $values);

        expect($built)->toBe(['created' => ['FW-1043-L-NAVY'], 'skipped' => 1]);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.generate', $this->product->public_id), ['values' => $values])
            ->assertInertiaFlash('toast.type', 'info');

        expect($this->product->variants()->count())->toBe(2);
    });

    it('builds all or nothing, and refuses attributes other than the ones its variations use', function () {
        app(ManageVariants::class)->create($this->manager, $this->product, [
            'sku' => 'FW-1043-M',
            'values' => catalogUiValues($this->size, ['M']),
        ]);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.generate', $this->product->public_id), [
                'values' => [...catalogUiValues($this->size, ['L']), ...catalogUiValues($this->colour, ['Navy'])],
            ])
            ->assertSessionHasErrors('values');

        expect($this->product->variants()->pluck('sku')->all())->toBe(['FW-1043-M']);
    });

    it('refuses more combinations than one build may make', function () {
        $many = catalogUiAttribute('Length', range(1, 11), 2);
        $more = catalogUiAttribute('Width', range(1, 10), 3);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.generate', $this->product->public_id), [
                'values' => [...$many->values()->pluck('public_id')->all(), ...$more->values()->pluck('public_id')->all()],
            ])
            ->assertSessionHasErrors(['values' => 'Those values make 110 combinations. Build up to 100 at a time.']);

        expect($this->product->variants()->count())->toBe(0);
    });

    it('adds a suffix when an SKU is taken anywhere, and always makes an SKU a scanner accepts', function () {
        catalogUiProduct('FW-1043-M', $this->kitchen);
        $bangla = catalogUiAttribute('রং', ['লাল'], 2);

        app(GenerateVariants::class)->handle($this->manager, $this->product, catalogUiValues($this->size, ['M']));

        expect($this->product->variants()->pluck('sku')->all())->toBe(['FW-1043-M-2']);

        $other = catalogUiProduct('FW-2000', $this->kitchen);

        app(GenerateVariants::class)->handle($this->manager, $other, catalogUiValues($bangla, ['লাল']));

        expect($other->variants()->value('sku'))->toMatch('/^FW-2000-[A-Z0-9][A-Z0-9._-]*$/');
    });

    it('is refused to a business account and to staff who may not create (§12)', function () {
        $values = catalogUiValues($this->size, ['M', 'L']);

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->post(route('admin.catalog.products.variants.generate', $this->product->public_id), ['values' => $values])
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->post(route('admin.catalog.products.variants.generate', $this->product->public_id), [])
            ->assertForbidden();

        expect($this->product->variants()->count())->toBe(0);
    });
});

it('confirms a catalogue change with a toast the page shows', function () {
    $product = catalogUiProduct('FW-1', $this->kitchen);

    $this->actingAs($this->manager)
        ->patch(route('admin.catalog.products.update', $product->public_id), [
            'name' => 'Renamed',
            'sku' => 'FW-1',
            'category_id' => $this->kitchen->public_id,
            'base_cost' => '1700.00',
            'wholesale_price' => '2100.00',
        ])
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', 'Renamed saved.');
});
