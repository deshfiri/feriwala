<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\ManageVariants;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\WholesalePriceResolver;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Quantity-based wholesale pricing (P3-6, §11.1, §12).
 *
 * The central wholesale price is among what §12 says a regular user may not
 * modify, so the refusals come first. Then the table's own rules — bands start
 * at two units, never repeat, never cost more per unit than the band before —
 * and the resolver's promise that buying more never costs more per unit.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => Category::create(['name' => 'Kitchen'])->id,
        'wholesale_price_minor' => 250000,
    ]);
});

/**
 * @param  array<int, array{0: int, 1: int}>  $bands  [min quantity, unit price minor]
 *
 * The pair is still given in minor units, as every existing call site already
 * reads; converted to the Taka decimal string the HTTP form now submits
 * (§36.1), so callers do not need to change.
 */
function catalogTierPayload(array $bands, ?string $variantId = null): array
{
    return [
        'variant_id' => $variantId,
        'tiers' => array_map(fn (array $band) => [
            'min_quantity' => $band[0],
            'unit_price_minor' => number_format($band[1] / 100, 2, '.', ''),
        ], $bands),
    ];
}

describe('only the platform sets wholesale pricing (§12)', function () {
    it('refuses a business account holder and staff who may only read', function () {
        $url = route('admin.catalog.products.price-tiers.update', $this->product->public_id);

        $this->actingAs(testBusinessAccount(AccountStatus::Active)->owner)
            ->put($url, catalogTierPayload([[10, 1]]))
            ->assertForbidden();

        $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
            ->put($url, catalogTierPayload([[10, 1]]))
            ->assertForbidden();

        expect(ProductPriceTier::query()->count())->toBe(0);
    });
});

describe('the tier table', function () {
    it('saves a table as Money, and replaces it whole on the next save', function () {
        $url = route('admin.catalog.products.price-tiers.update', $this->product->public_id);

        $this->actingAs($this->manager)
            ->put($url, catalogTierPayload([[50, 220000], [10, 240000]]))
            ->assertSessionHasNoErrors();

        $tiers = ProductPriceTier::query()->orderBy('min_quantity')->get();

        expect($tiers->pluck('min_quantity')->all())->toBe([10, 50])
            ->and($tiers[0]->unit_price_minor)->toBeInstanceOf(Money::class)
            ->and($tiers[0]->unit_price_minor->minorUnits)->toBe(240000);

        $this->actingAs($this->manager)
            ->put($url, catalogTierPayload([[20, 230000]]))
            ->assertSessionHasNoErrors();

        expect(ProductPriceTier::query()->pluck('min_quantity')->all())->toBe([20]);
    });

    it('clears the table when saved empty', function () {
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 10, 'unit_price_minor' => 240000]);

        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.price-tiers.update', $this->product->public_id), ['variant_id' => null])
            ->assertSessionHasNoErrors();

        expect(ProductPriceTier::query()->count())->toBe(0);
    });

    it('refuses a band from one unit, and a repeated starting quantity', function () {
        $url = route('admin.catalog.products.price-tiers.update', $this->product->public_id);

        $this->actingAs($this->manager)
            ->put($url, catalogTierPayload([[1, 240000]]))
            ->assertSessionHasErrors('tiers.0.min_quantity');

        $this->actingAs($this->manager)
            ->put($url, catalogTierPayload([[10, 240000], [10, 230000]]))
            ->assertSessionHasErrors('tiers.0.min_quantity');

        expect(ProductPriceTier::query()->count())->toBe(0);
    });

    it('refuses a band that costs more per unit than the one before it, or than the base', function () {
        $url = route('admin.catalog.products.price-tiers.update', $this->product->public_id);

        $this->actingAs($this->manager)
            ->put($url, catalogTierPayload([[10, 240000], [50, 245000]]))
            ->assertSessionHasErrors('tiers');

        $this->actingAs($this->manager)
            ->put($url, catalogTierPayload([[10, 260000]]))
            ->assertSessionHasErrors('tiers');

        expect(ProductPriceTier::query()->count())->toBe(0);
    });

    it('holds a starting quantity unique per scope in the database', function () {
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 10, 'unit_price_minor' => 240000]);

        expect(fn () => ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 10, 'unit_price_minor' => 230000]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('holds a band from at least two units in the database', function () {
        expect(fn () => ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 1, 'unit_price_minor' => 1]))
            ->toThrow(QueryException::class, 'product_price_tiers_quantity_at_least_two');
    });

    it('keeps a variation’s table apart from the product’s', function () {
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-RC-L', 'combination_key' => 'k']);
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 10, 'unit_price_minor' => 240000]);

        $this->actingAs($this->manager)
            ->put(
                route('admin.catalog.products.price-tiers.update', $this->product->public_id),
                catalogTierPayload([[10, 235000]], $variant->public_id),
            )
            ->assertSessionHasNoErrors();

        expect(ProductPriceTier::query()->whereNull('product_variant_id')->firstOrFail()->unit_price_minor->minorUnits)->toBe(240000)
            ->and(ProductPriceTier::query()->where('product_variant_id', $variant->id)->count())->toBe(1);
    });

    it('cannot reach another product’s variation', function () {
        $other = Product::create(['name' => 'Other', 'sku' => 'FW-9', 'category_id' => $this->product->category_id]);
        $foreign = ProductVariant::create(['product_id' => $other->id, 'sku' => 'FW-9-M', 'combination_key' => 'k']);

        $this->actingAs($this->manager)
            ->put(
                route('admin.catalog.products.price-tiers.update', $this->product->public_id),
                catalogTierPayload([[10, 1]], $foreign->public_id),
            )
            ->assertNotFound();

        expect(ProductPriceTier::query()->count())->toBe(0);
    });
});

describe('what one unit costs, resolved on the server', function () {
    beforeEach(function () {
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 10, 'unit_price_minor' => 240000]);
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 50, 'unit_price_minor' => 220000]);

        $this->resolver = app(WholesalePriceResolver::class);
    });

    it('charges the base below the first band, and each band from where it starts', function () {
        $price = fn (int $quantity) => $this->resolver->unitPrice($this->product, null, $quantity)->minorUnits;

        expect($price(1))->toBe(250000)
            ->and($price(9))->toBe(250000)
            ->and($price(10))->toBe(240000)
            ->and($price(49))->toBe(240000)
            ->and($price(50))->toBe(220000)
            ->and($price(5000))->toBe(220000);
    });

    it('uses a variation’s own table when it has one, and the product’s when it does not', function () {
        $plain = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-RC-M', 'combination_key' => 'm']);
        $special = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-RC-L', 'combination_key' => 'l']);
        ProductPriceTier::create(['product_id' => $this->product->id, 'product_variant_id' => $special->id, 'min_quantity' => 5, 'unit_price_minor' => 200000]);

        expect($this->resolver->unitPrice($this->product, $plain, 10)->minorUnits)->toBe(240000)
            ->and($this->resolver->unitPrice($this->product, $special, 10)->minorUnits)->toBe(200000)
            ->and($this->resolver->unitPrice($this->product, $special, 4)->minorUnits)->toBe(250000);
    });

    it('never charges more per unit for buying more, even after the base price is cut', function () {
        // The bands were written against ৳2,500. The base is now ৳2,300, which
        // leaves the first band above it: the base is what gets charged.
        $this->product->forceFill(['wholesale_price_minor' => 230000])->save();

        expect($this->resolver->unitPrice($this->product->refresh(), null, 10)->minorUnits)->toBe(230000)
            ->and($this->resolver->unitPrice($this->product, null, 50)->minorUnits)->toBe(220000);
    });

    it('refuses to price a quantity below one', function () {
        expect(fn () => $this->resolver->unitPrice($this->product, null, 0))->toThrow(InvalidArgumentException::class);
    });

    it('shows each scope on the editor, flagging a band the server no longer charges', function () {
        $this->product->forceFill(['wholesale_price_minor' => 230000])->save();

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $this->product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('price_tiers.0.variant_id', null)
                ->where('price_tiers.0.base_price.minor_units', 230000)
                ->where('price_tiers.0.tiers.0.min_quantity', 10)
                ->where('price_tiers.0.tiers.0.applies', false)
                ->where('price_tiers.0.tiers.1.applies', true),
            );
    });
});

describe('removing a draft', function () {
    it('removes a draft variation together with its own tiers', function () {
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-RC-L', 'combination_key' => 'k']);
        ProductPriceTier::create(['product_id' => $this->product->id, 'product_variant_id' => $variant->id, 'min_quantity' => 5, 'unit_price_minor' => 1]);

        app(ManageVariants::class)->delete($this->manager, $variant);

        expect(ProductVariant::query()->count())->toBe(0)
            ->and(ProductPriceTier::query()->count())->toBe(0);
    });

    it('removes a draft product together with every tier', function () {
        ProductPriceTier::create(['product_id' => $this->product->id, 'min_quantity' => 5, 'unit_price_minor' => 1]);

        app(ManageProducts::class)->delete($this->manager, $this->product);

        expect(Product::query()->count())->toBe(0)
            ->and(ProductPriceTier::query()->count())->toBe(0);
    });
});
