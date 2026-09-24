<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\SetPriceTiers;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Order-quantity and selling-price bounds (P3-7, §11.1, §14, §15.1).
 *
 * Blank means no bound and never zero. A minimum above its maximum is a product
 * nobody can order or sell at any figure, so the ordering rules hold in the
 * database as well as the form — and a quantity tier nobody could ever reach is
 * refused from both directions.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => Category::create(['name' => 'Kitchen'])->id,
        'base_cost' => Money::fromDecimal('1800.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2500.00', Currency::BDT),
    ]);
});

function catalogBoundsPayload(Product $product, array $overrides = []): array
{
    return [
        'name' => $product->name,
        'sku' => $product->sku,
        'category_id' => Category::query()->value('public_id'),
        'base_cost' => '1800.00',
        'wholesale_price' => '2500.00',
        ...$overrides,
    ];
}

describe('saving the bounds', function () {
    it('holds order quantities as integers and selling prices as Money', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogBoundsPayload($this->product, [
                'min_order_quantity' => 6,
                'max_order_quantity' => 120,
                'minimum_selling_price' => '2800.00',
                'suggested_selling_price' => '2990.00',
                'maximum_selling_price' => '3500.00',
            ]))
            ->assertSessionHasNoErrors();

        $this->product->refresh();

        expect($this->product->min_order_quantity)->toBe(6)
            ->and($this->product->max_order_quantity)->toBe(120)
            ->and($this->product->suggested_selling_price)->toBeInstanceOf(Money::class)
            ->and($this->product->suggested_selling_price?->toDecimal())->toBe('2990.00');
    });

    it('treats blank as no bound, never as zero', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogBoundsPayload($this->product, [
                'min_order_quantity' => '',
                'max_order_quantity' => '',
                'minimum_selling_price' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->product->refresh();

        expect($this->product->min_order_quantity)->toBe(1)
            ->and($this->product->max_order_quantity)->toBeNull()
            ->and($this->product->minimum_selling_price)->toBeNull();
    });

    it('is not something a business account can change (§12)', function () {
        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogBoundsPayload($this->product, [
                'minimum_selling_price' => '0.01',
            ]))
            ->assertForbidden();

        expect($this->product->refresh()->minimum_selling_price)->toBeNull();
    });
});

describe('the bounds keep their order', function () {
    it('refuses a maximum order quantity below the minimum', function () {
        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogBoundsPayload($this->product, [
                'min_order_quantity' => 10,
                'max_order_quantity' => 5,
            ]))
            ->assertSessionHasErrors('max_order_quantity');
    });

    it('refuses a minimum selling price above the maximum, and a suggestion outside them', function () {
        $url = route('admin.catalog.products.update', $this->product->public_id);

        $this->actingAs($this->manager)
            ->patch($url, catalogBoundsPayload($this->product, [
                'minimum_selling_price' => '4000.00',
                'maximum_selling_price' => '3000.00',
            ]))
            ->assertSessionHasErrors('minimum_selling_price');

        $this->actingAs($this->manager)
            ->patch($url, catalogBoundsPayload($this->product, [
                'minimum_selling_price' => '2800.00',
                'suggested_selling_price' => '2700.00',
            ]))
            ->assertSessionHasErrors('suggested_selling_price');

        expect($this->product->refresh()->minimum_selling_price)->toBeNull();
    });

    it('holds the order-quantity range in the database', function () {
        expect(fn () => $this->product->forceFill(['min_order_quantity' => 10, 'max_order_quantity' => 5])->save())
            ->toThrow(QueryException::class, 'products_order_quantity_range');
    });

    it('holds the selling-price range in the database', function () {
        expect(fn () => $this->product->forceFill([
            'minimum_selling_price' => Money::fromDecimal('3000.00', Currency::BDT),
            'suggested_selling_price' => Money::fromDecimal('2000.00', Currency::BDT),
        ])->save())->toThrow(QueryException::class, 'products_selling_price_range');
    });

    it('holds the minimum order quantity at one or more in the database', function () {
        expect(fn () => $this->product->forceFill(['min_order_quantity' => 0])->save())
            ->toThrow(QueryException::class, 'products_min_order_quantity_positive');
    });
});

describe('tiers nobody could reach', function () {
    it('refuses a maximum order quantity below an existing tier', function () {
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 50, 'unit_price' => Money::fromDecimal('2200.00', Currency::BDT)]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $this->product->public_id), catalogBoundsPayload($this->product, [
                'max_order_quantity' => 40,
            ]))
            ->assertSessionHasErrors('max_order_quantity');

        expect($this->product->refresh()->max_order_quantity)->toBeNull();
    });

    it('refuses a tier starting above the maximum order quantity', function () {
        $this->product->forceFill(['max_order_quantity' => 40])->save();

        expect(fn () => app(SetPriceTiers::class)->handle($this->manager, $this->product, null, [
            ['min_quantity' => 50, 'unit_price' => '2200.00'],
        ]))->toThrow(CatalogRefused::class, 'can never apply');

        expect(ProductPriceTier::query()->count())->toBe(0);
    });
});

describe('using the bounds', function () {
    it('accepts only quantities inside them, and never clamps one', function () {
        $this->product->forceFill(['min_order_quantity' => 6, 'max_order_quantity' => 120])->save();

        expect($this->product->acceptsQuantity(5))->toBeFalse()
            ->and($this->product->acceptsQuantity(6))->toBeTrue()
            ->and($this->product->acceptsQuantity(120))->toBeTrue()
            ->and($this->product->acceptsQuantity(121))->toBeFalse();

        $this->product->forceFill(['max_order_quantity' => null])->save();

        expect($this->product->acceptsQuantity(100000))->toBeTrue();
    });

    it('shows the bounds on the editor with the server’s rendering of each price', function () {
        $this->product->forceFill([
            'min_order_quantity' => 6,
            'suggested_selling_price' => Money::fromDecimal('2990.00', Currency::BDT),
        ])->save();

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.min_order_quantity', 6)
                ->where('product.max_order_quantity', null)
                ->where('product.suggested_selling_price.amount', '2990.00')
                ->where('product.minimum_selling_price', null),
            );
    });
});
