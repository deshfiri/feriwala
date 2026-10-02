<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Data\ProductLogistics;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Physical logistics and packaging data for a product, with an optional
 * per-variant override (beta-critical batch, Commit 1).
 *
 * One canonical unit per measurement -- whole grams, centimetres, integer
 * piece counts -- server-authoritative and never a float. A variant's own
 * column wins when set; the product's applies otherwise, resolved
 * independently per field.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);

    $this->product = Product::create([
        'name' => 'Cotton Panjabi',
        'sku' => 'FW-2043',
        'category_id' => Category::create(['name' => 'Clothing'])->id,
        'base_cost' => Money::fromDecimal('1500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2490.00', Currency::BDT),
    ]);
});

it('saves a product\'s logistics figures through the admin form', function () {
    $this->actingAs($this->manager)->patch(
        route('admin.catalog.products.update', $this->product->public_id),
        [
            'name' => $this->product->name,
            'sku' => $this->product->sku,
            'category_id' => $this->product->category->public_id,
            'base_cost' => '1500.00',
            'wholesale_price' => '2490.00',
            'net_weight_grams' => '450',
            'shipping_weight_grams' => '520',
            'length_cm' => '30.50',
            'width_cm' => '20.00',
            'height_cm' => '5.25',
            'ships_by_box' => true,
            'pieces_per_box' => '12',
            'box_weight_grams' => '6500',
            'box_length_cm' => '40.00',
            'box_width_cm' => '30.00',
            'box_height_cm' => '25.00',
            'is_fragile' => false,
        ],
    )->assertRedirect()->assertSessionHasNoErrors();

    $this->product->refresh();

    expect($this->product->net_weight_grams)->toBe(450)
        ->and($this->product->shipping_weight_grams)->toBe(520)
        ->and($this->product->length_cm)->toBe('30.50')
        ->and($this->product->width_cm)->toBe('20.00')
        ->and($this->product->height_cm)->toBe('5.25')
        ->and($this->product->ships_by_box)->toBeTrue()
        ->and($this->product->pieces_per_box)->toBe(12)
        ->and($this->product->box_weight_grams)->toBe(6500)
        ->and($this->product->is_fragile)->toBeFalse();
});

it('rejects a zero or negative logistics figure', function () {
    foreach (['net_weight_grams' => '0', 'length_cm' => '-5', 'pieces_per_box' => '0'] as $field => $value) {
        $this->actingAs($this->manager)->patch(
            route('admin.catalog.products.update', $this->product->public_id),
            [
                'name' => $this->product->name,
                'category_id' => $this->product->category->public_id,
                'base_cost' => '1500.00',
                'wholesale_price' => '2490.00',
                $field => $value,
            ],
        )->assertInvalid([$field]);
    }
});

it('leaves logistics figures untouched when the product has none yet', function () {
    expect($this->product->net_weight_grams)->toBeNull()
        ->and($this->product->ships_by_box)->toBeFalse()
        ->and($this->product->is_fragile)->toBeFalse();

    // No stock requirement: a product with zero stock still accepts logistics data.
    $logistics = $this->product->logistics();
    expect($logistics)->toBeInstanceOf(ProductLogistics::class)
        ->and($logistics->netWeightGrams)->toBeNull();
});

describe('variant override', function () {
    beforeEach(function () {
        $this->product->forceFill([
            'net_weight_grams' => 400,
            'length_cm' => '20.00',
            'ships_by_box' => false,
            'is_fragile' => false,
        ])->save();

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FW-2043-M',
            'combination_key' => 'x1',
        ]);
    });

    it('inherits every field from the product when the variant overrides none', function () {
        $logistics = $this->variant->logistics();

        expect($logistics->netWeightGrams)->toBe(400)
            ->and($logistics->lengthCm)->toBe('20.00')
            ->and($logistics->shipsByBox)->toBeFalse()
            ->and($logistics->isFragile)->toBeFalse();
    });

    it('overrides only the specific field set on the variant, inheriting the rest', function () {
        $this->variant->forceFill(['net_weight_grams' => 600, 'is_fragile' => true])->save();

        $logistics = $this->variant->logistics();

        expect($logistics->netWeightGrams)->toBe(600)
            ->and($logistics->isFragile)->toBeTrue()
            // Not overridden -- still the product's own figures.
            ->and($logistics->lengthCm)->toBe('20.00')
            ->and($logistics->shipsByBox)->toBeFalse();
    });

    it('saves a variant override through the admin form and reports it back as an override', function () {
        $this->actingAs($this->manager)->patch(
            route('admin.catalog.products.variants.update', [$this->product->public_id, $this->variant->public_id]),
            [
                'sku' => $this->variant->sku,
                'net_weight_grams' => '650',
            ],
        )->assertRedirect()->assertSessionHasNoErrors();

        expect($this->variant->refresh()->net_weight_grams)->toBe(650)
            // Untouched fields stay null -- still inherited, not forced to the product's current figure.
            ->and($this->variant->length_cm)->toBeNull();
    });
});
