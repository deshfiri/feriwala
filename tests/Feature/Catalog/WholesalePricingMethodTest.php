<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Enums\WholesalePricingMethod;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\WholesalePriceCalculator;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Wholesale pricing method: Fixed (typed) or Percentage (base cost + markup).
 *
 * The server's calculation is the only one that counts, so most of this file
 * posts a deliberately wrong `wholesale_price` next to a percentage and checks
 * what was stored. Money is flat Taka throughout (D26); nothing here has a
 * minor unit.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Electronics']);
    Brand::create(['name' => 'Walton']);
});

function wholesalePricingProduct(array $attributes = []): Product
{
    return Product::create([
        'name' => 'Walton Rice Cooker',
        'sku' => 'FWRC00001',
        'category_id' => Category::query()->value('id'),
        'base_cost' => Money::fromDecimal('100.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('135.00', Currency::BDT),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wholesalePricingPayload(array $overrides = []): array
{
    return [
        'name' => 'Walton Rice Cooker 2.8L',
        'sku' => 'fwrc28abc',
        'category_id' => Category::query()->value('public_id'),
        'base_cost' => '100.00',
        'wholesale_price' => '135.00',
        ...$overrides,
    ];
}

function wholesalePricingUpdate(Product $product, array $overrides = []): array
{
    return wholesalePricingPayload([
        'name' => $product->name,
        'sku' => $product->sku,
        ...$overrides,
    ]);
}

describe('the default and the migration', function () {
    it('is fixed by default, on a new product and on a form that sends no method', function () {
        expect(Product::create([
            'name' => 'Bare', 'sku' => 'FWBARE001', 'category_id' => $this->category->id,
        ])->refresh()->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload())
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'FWRC28ABC')->firstOrFail();

        expect($product->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed)
            ->and($product->wholesale_markup_percentage)->toBeNull()
            ->and($product->wholesale_price->toDecimal())->toBe('135.00');
    });

    it('reads a row written without the new columns as fixed, with its price untouched', function () {
        // What every product that existed before the migration looks like.
        $id = DB::table('products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'slug' => 'legacy',
            'sku' => 'FWLEGACY1',
            'name' => 'Legacy',
            'category_id' => $this->category->id,
            'currency_code' => 'BDT',
            'base_cost' => '1800.00',
            'wholesale_price' => '2100.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = Product::query()->findOrFail($id);

        expect($legacy->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed)
            ->and($legacy->wholesale_markup_percentage)->toBeNull()
            ->and($legacy->wholesale_price->toDecimal())->toBe('2100.00');
    });

    it('refuses, in the database, a percentage row without its percentage', function () {
        expect(fn () => DB::table('products')->where('id', wholesalePricingProduct()->id)->update([
            'wholesale_pricing_method' => 'percentage',
        ]))->toThrow(QueryException::class);
    });
});

describe('fixed pricing', function () {
    it('accepts a manual wholesale price and clears any markup sent with it', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload([
                'wholesale_pricing_method' => 'fixed',
                'wholesale_price' => '135.00',
                'wholesale_markup_percentage' => '50',
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'FWRC28ABC')->firstOrFail();

        expect($product->wholesale_price->toDecimal())->toBe('135.00')
            ->and($product->wholesale_markup_percentage)->toBeNull();
    });

    it('still requires the wholesale price', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload([
                'wholesale_pricing_method' => 'fixed',
                'wholesale_price' => '',
            ]))
            ->assertSessionHasErrors('wholesale_price');
    });

    it('refuses an unknown method', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload(['wholesale_pricing_method' => 'margin']))
            ->assertSessionHasErrors('wholesale_pricing_method');
    });
});

describe('percentage pricing', function () {
    it('calculates the price on the server and ignores the one the browser sent', function (string $base, string $percent, string $expected) {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload([
                'base_cost' => $base,
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => $percent,
                'wholesale_price' => '1.00', // tampered
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'FWRC28ABC')->firstOrFail();

        expect($product->wholesale_pricing_method)->toBe(WholesalePricingMethod::Percentage)
            ->and($product->wholesale_price->toDecimal())->toBe($expected)
            ->and($product->wholesale_markup_percentage)->toBe(bcadd($percent, '0', 2));
    })->with([
        'whole percent' => ['100.00', '20', '120.00'],
        'ten percent' => ['500.00', '10', '550.00'],
        'decimal percent' => ['250.00', '12.5', '281.25'],
        'rounds half away from zero' => ['33.33', '12.5', '37.50'],
        'zero markup' => ['100.00', '0', '100.00'],
        'the ceiling' => ['100.00', '1000', '1100.00'],
    ]);

    it('uses one formula for the calculator and the stored price', function () {
        expect((new WholesalePriceCalculator)->wholesalePrice(Money::fromDecimal('250.00', Currency::BDT), '12.5')->toDecimal())
            ->toBe('281.25');
    });

    it('asks for the base cost first', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload([
                'base_cost' => '',
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '20',
            ]))
            ->assertSessionHasErrors(['base_cost' => 'Enter the Base Cost before using percentage pricing.']);

        expect(Product::query()->where('sku', 'FWRC28ABC')->exists())->toBeFalse();
    });

    it('requires a markup percentage', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload([
                'wholesale_pricing_method' => 'percentage',
            ]))
            ->assertSessionHasErrors('wholesale_markup_percentage');
    });

    it('refuses a negative, malformed, infinite, over-precise or too large percentage', function (string $percent) {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), wholesalePricingPayload([
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => $percent,
            ]))
            ->assertSessionHasErrors('wholesale_markup_percentage');

        expect(Product::query()->where('sku', 'FWRC28ABC')->exists())->toBeFalse();
    })->with(['-5', 'abc', '1e2', '12.345', '1000.01', 'INF', 'NaN', '20%', '1,5', ' ']);

    it('recalculates when the base cost changes', function () {
        $product = wholesalePricingProduct();
        $payload = wholesalePricingUpdate($product, [
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '20',
        ]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), $payload)
            ->assertSessionHasNoErrors();

        expect($product->refresh()->wholesale_price->toDecimal())->toBe('120.00');

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), [...$payload, 'base_cost' => '500.00'])
            ->assertSessionHasNoErrors();

        expect($product->refresh()->wholesale_price->toDecimal())->toBe('600.00');
    });

    it('recalculates a stored percentage product when only its base cost is written', function () {
        $product = wholesalePricingProduct([
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '10.00',
        ]);

        app(ManageProducts::class)->update($this->manager, $product, ['base_cost' => '200.00']);

        expect($product->refresh()->wholesale_price->toDecimal())->toBe('220.00');
    });

    it('goes back to fixed, keeping the calculated price to edit and dropping the percentage', function () {
        $product = wholesalePricingProduct();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), wholesalePricingUpdate($product, [
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '20',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), wholesalePricingUpdate($product, [
                'wholesale_pricing_method' => 'fixed',
                'wholesale_price' => '120.00', // the calculated figure, now typed
                'wholesale_markup_percentage' => '20',
            ]))
            ->assertSessionHasNoErrors();

        expect($product->refresh()->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed)
            ->and($product->wholesale_markup_percentage)->toBeNull()
            ->and($product->wholesale_price->toDecimal())->toBe('120.00');

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), wholesalePricingUpdate($product, [
                'wholesale_price' => '125.00',
            ]))
            ->assertSessionHasNoErrors();

        expect($product->refresh()->wholesale_price->toDecimal())->toBe('125.00');
    });

    it('loads the saved method and percentage into the edit page', function () {
        $product = wholesalePricingProduct([
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '12.50',
            'wholesale_price' => Money::fromDecimal('112.50', Currency::BDT),
        ]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.wholesale_pricing_method', 'percentage')
                ->where('product.wholesale_markup_percentage', '12.50')
                ->where('product.wholesale_price.amount', '112.50'));
    });
});

describe('what a pricing change must not touch', function () {
    it('leaves the selling-price guidance alone', function () {
        $product = wholesalePricingProduct([
            'suggested_selling_price' => Money::fromDecimal('200.00', Currency::BDT),
            'minimum_selling_price' => Money::fromDecimal('150.00', Currency::BDT),
            'maximum_selling_price' => Money::fromDecimal('300.00', Currency::BDT),
        ]);

        app(ManageProducts::class)->update($this->manager, $product, [
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '40',
        ]);

        $product->refresh();

        expect($product->wholesale_price->toDecimal())->toBe('140.00')
            ->and($product->suggested_selling_price?->toDecimal())->toBe('200.00')
            ->and($product->minimum_selling_price?->toDecimal())->toBe('150.00')
            ->and($product->maximum_selling_price?->toDecimal())->toBe('300.00');
    });

    it('does not rewrite an order that was already priced', function () {
        $product = wholesalePricingProduct();
        $order = Order::factory()->create();

        $item = OrderItem::create([
            'order_id' => $order->id,
            'line_number' => 1,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity' => 2,
            'currency_code' => 'BDT',
            'unit_price' => Money::fromDecimal('135.00', Currency::BDT),
            'line_subtotal' => Money::fromDecimal('270.00', Currency::BDT),
            'line_total' => Money::fromDecimal('270.00', Currency::BDT),
            'created_at' => now(),
        ]);

        app(ManageProducts::class)->update($this->manager, $product, [
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '900',
        ]);

        $item->refresh();

        expect($product->refresh()->wholesale_price->toDecimal())->toBe('1000.00')
            ->and($item->unit_price->toDecimal())->toBe('135.00')
            ->and($item->line_total->toDecimal())->toBe('270.00');
    });
});

describe('audit', function () {
    it('records the previous and new method, percentage and price', function () {
        $product = wholesalePricingProduct();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), wholesalePricingUpdate($product, [
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '20',
            ]))
            ->assertSessionHasNoErrors();

        $entry = AuditLog::query()
            ->where('action', 'catalog.product_updated')
            ->where('auditable_id', $product->id)
            ->latest('id')
            ->firstOrFail();

        expect($entry->before)->toMatchArray([
            'wholesale_pricing_method' => 'fixed',
            'wholesale_markup_percentage' => null,
            'wholesale_price' => '135.00',
        ])->and($entry->after)->toMatchArray([
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '20.00',
            'wholesale_price' => '120.00',
        ]);
    });
});

describe('permissions', function () {
    it('refuses a business account holder and read-only staff, changing nothing', function () {
        $product = wholesalePricingProduct();
        $payload = wholesalePricingUpdate($product, [
            'wholesale_pricing_method' => 'percentage',
            'wholesale_markup_percentage' => '20',
        ]);

        foreach ([testBusinessAccount(AccountStatus::Active)->owner, testPlatformStaff(PlatformRole::InventoryManager)] as $actor) {
            $this->actingAs($actor)
                ->patch(route('admin.catalog.products.update', $product->public_id), $payload)
                ->assertForbidden();
            $this->actingAs($actor)
                ->post(route('admin.catalog.products.price-preview'), ['base_cost' => '100.00', 'wholesale_markup_percentage' => '20'])
                ->assertForbidden();
        }

        expect($product->refresh()->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed)
            ->and($product->wholesale_price->toDecimal())->toBe('135.00');
    });

    it('does not let a bulk action set the method or percentage', function () {
        $product = wholesalePricingProduct();

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), [
                'action' => 'status',
                'products' => [$product->public_id],
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '20',
            ])
            ->assertSessionHasErrors(['wholesale_pricing_method', 'wholesale_markup_percentage']);

        expect($product->refresh()->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed);
    });
});

describe('the live preview', function () {
    it('answers with the same figure the save will store', function () {
        $this->actingAs($this->manager)
            ->postJson(route('admin.catalog.products.price-preview'), [
                'base_cost' => '250.00',
                'wholesale_markup_percentage' => '12.5',
            ])
            ->assertOk()
            ->assertJsonPath('wholesale_price.amount', '281.25')
            ->assertJsonPath('wholesale_price.formatted', '৳281.25')
            ->assertJsonPath('base_cost.formatted', '৳250.00')
            ->assertJsonPath('markup_percentage', '12.5');
    });

    it('refuses an invalid percentage and a missing base cost', function () {
        $this->actingAs($this->manager)
            ->postJson(route('admin.catalog.products.price-preview'), ['base_cost' => '100.00', 'wholesale_markup_percentage' => '-1'])
            ->assertJsonValidationErrors('wholesale_markup_percentage');

        $this->actingAs($this->manager)
            ->postJson(route('admin.catalog.products.price-preview'), ['wholesale_markup_percentage' => '10'])
            ->assertJsonValidationErrors(['base_cost' => 'Enter the Base Cost before using percentage pricing.']);
    });
});

describe('variations', function () {
    beforeEach(function () {
        $this->product = wholesalePricingProduct(['base_cost' => Money::fromDecimal('1500.00', Currency::BDT)]);

        $size = ProductAttribute::create(['name' => 'Size']);
        $this->medium = ProductAttributeValue::create(['product_attribute_id' => $size->id, 'value' => 'M', 'sort_order' => 0]);
        $this->large = ProductAttributeValue::create(['product_attribute_id' => $size->id, 'value' => 'L', 'sort_order' => 1]);
    });

    it('calculates each variation from its own base cost, never the product’s', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FWRC-M',
                'values' => [$this->medium->public_id],
                'base_cost' => '200.00',
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '10',
                'wholesale_price' => '1.00', // tampered
            ])
            ->assertSessionHasNoErrors();

        $variant = ProductVariant::query()->where('sku', 'FWRC-M')->firstOrFail();

        expect($variant->wholesale_pricing_method)->toBe(WholesalePricingMethod::Percentage)
            ->and($variant->wholesale_price?->toDecimal())->toBe('220.00')
            ->and($variant->effectiveWholesalePrice()->toDecimal())->toBe('220.00')
            ->and($this->product->refresh()->wholesale_price->toDecimal())->toBe('135.00');

        // The product's own cost moving does not move the variation.
        app(ManageProducts::class)->update($this->manager, $this->product, ['base_cost' => '9000.00']);

        expect($variant->refresh()->wholesale_price?->toDecimal())->toBe('220.00');
    });

    it('needs the variation’s own base cost for a percentage price', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FWRC-M',
                'values' => [$this->medium->public_id],
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '10',
            ])
            ->assertSessionHasErrors(['base_cost' => 'Enter the Base Cost before using percentage pricing.']);
    });

    it('keeps a fixed override as it was and refuses a bad percentage', function () {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FWRC-L',
            'combination_key' => 'x',
            'wholesale_price' => Money::fromDecimal('2000.00', Currency::BDT),
        ]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.variants.update', [$this->product->public_id, $variant->public_id]), [
                'sku' => 'FWRC-L',
                'wholesale_price' => '2000.00',
                'base_cost' => '',
                'is_active' => '0',
            ])
            ->assertSessionHasNoErrors();

        expect($variant->refresh()->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed)
            ->and($variant->wholesale_price?->toDecimal())->toBe('2000.00')
            ->and($variant->base_cost)->toBeNull();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.variants.update', [$this->product->public_id, $variant->public_id]), [
                'sku' => 'FWRC-L',
                'base_cost' => '100.00',
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '-3',
            ])
            ->assertSessionHasErrors('wholesale_markup_percentage');
    });

    it('is refused to a business account holder', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->post(route('admin.catalog.products.variants.store', $this->product->public_id), [
                'sku' => 'FWRC-M',
                'values' => [$this->medium->public_id],
                'base_cost' => '200.00',
                'wholesale_pricing_method' => 'percentage',
                'wholesale_markup_percentage' => '10',
            ])
            ->assertForbidden();

        expect(ProductVariant::query()->count())->toBe(0);
    });
});
