<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Enums\WholesalePricingMethod;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money\Money;
use Database\Seeders\FakeOrderSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Existing wholesale prices are sacred: the pricing-method migration, an edit
 * page being opened, an unrelated field being saved and a seeder running must
 * all leave every typed price exactly as it was.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Electronics']);
});

function preservationMigration(): object
{
    return require database_path('migrations/2026_11_11_100000_add_wholesale_pricing_method_to_products.php');
}

/**
 * A product row as it was written before the method columns existed.
 *
 * @param  array<string, mixed>  $attributes
 */
function preservationLegacyProduct(string $sku, array $attributes = []): int
{
    return DB::table('products')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'slug' => Str::lower($sku),
        'sku' => $sku,
        'name' => "Legacy {$sku}",
        'category_id' => Category::query()->value('id'),
        'currency_code' => 'BDT',
        'base_cost' => '0.00',
        'wholesale_price' => '120.00',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function preservationSnapshot(): array
{
    return [
        'products' => DB::table('products')->orderBy('id')
            ->get(['id', 'wholesale_price', 'base_cost', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price'])
            ->map(fn ($row) => (array) $row)->all(),
        'variants' => DB::table('product_variants')->orderBy('id')
            ->get(['id', 'wholesale_price', 'base_cost'])->map(fn ($row) => (array) $row)->all(),
    ];
}

describe('the migration', function () {
    it('only adds the method fields: every price, bound and variant price is identical afterwards, and after a rollback', function () {
        $migration = preservationMigration();
        $migration->down();

        $withVariants = preservationLegacyProduct('FWLEG0001', [
            'wholesale_price' => '2490.55',
            'base_cost' => '1800.00',
            'minimum_selling_price' => '2500.10',
            'maximum_selling_price' => '3000.00',
            'suggested_selling_price' => '2750.25',
        ]);
        preservationLegacyProduct('FWLEG0002'); // ৳120, base cost 0.00
        preservationLegacyProduct('FWLEG0003', ['wholesale_price' => '0.00']);

        foreach ([['FWLEG0001-M', '2600.75', '1900.00'], ['FWLEG0001-L', null, null]] as [$sku, $price, $cost]) {
            DB::table('product_variants')->insert([
                'public_id' => (string) Str::ulid(),
                'product_id' => $withVariants,
                'sku' => $sku,
                'combination_key' => $sku,
                'currency_code' => 'BDT',
                'wholesale_price' => $price,
                'base_cost' => $cost,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $before = preservationSnapshot();

        $migration->up();

        expect(preservationSnapshot())->toBe($before)
            ->and(DB::table('products')->where('wholesale_pricing_method', '!=', 'fixed')->count())->toBe(0)
            ->and(DB::table('products')->whereNotNull('wholesale_markup_percentage')->count())->toBe(0)
            ->and(DB::table('product_variants')->where('wholesale_pricing_method', '!=', 'fixed')->count())->toBe(0);

        // Rolling back drops only the new columns.
        $migration->down();

        expect(Schema::hasColumn('products', 'wholesale_pricing_method'))->toBeFalse()
            ->and(preservationSnapshot())->toBe($before);

        $migration->up();
    });

    it('shows a legacy product as Fixed with its exact price, decimal and zero base cost included', function () {
        $migration = preservationMigration();
        $migration->down();
        $id = preservationLegacyProduct('FWLEG0004', ['wholesale_price' => '1234.50', 'base_cost' => '0.00']);
        $migration->up();

        $product = Product::query()->findOrFail($id);

        expect($product->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed)
            ->and($product->wholesale_markup_percentage)->toBeNull()
            ->and($product->wholesale_price->toDecimal())->toBe('1234.50');

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $product->public_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.wholesale_pricing_method', 'fixed')
                ->where('product.wholesale_markup_percentage', null)
                ->where('product.wholesale_price.amount', '1234.50'));
    });
});

describe('opening and resaving an existing product', function () {
    /** The form as the page would post it back, edited only where asked. */
    function preservationFormPost(Product $product, array $edits = []): array
    {
        return [
            'name' => $product->name,
            'sku' => $product->sku,
            'category_id' => $product->category->public_id,
            'base_cost' => $product->base_cost->toDecimal(),
            'wholesale_pricing_method' => $product->wholesale_pricing_method->value,
            'wholesale_price' => $product->wholesale_price->toDecimal(),
            ...$edits,
        ];
    }

    it('keeps the price exactly when saved untouched or when an unrelated field changes', function (string $price, string $cost) {
        $id = preservationLegacyProduct('FWLEG0005', ['wholesale_price' => $price, 'base_cost' => $cost]);
        $product = Product::query()->findOrFail($id);

        // Opening the page writes nothing.
        $this->actingAs($this->manager)->get(route('admin.catalog.products.edit', $product->public_id))->assertOk();
        expect(DB::table('products')->where('id', $id)->value('wholesale_price'))->toBe($price);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), preservationFormPost($product))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), preservationFormPost($product, [
                'name' => 'Renamed',
                'description' => 'New description',
                'category_id' => Category::create(['name' => 'Other'])->public_id,
            ]))
            ->assertSessionHasNoErrors();

        $row = DB::table('products')->where('id', $id)->first();

        expect($row->wholesale_price)->toBe($price)
            ->and($row->base_cost)->toBe($cost)
            ->and($row->name)->toBe('Renamed')
            ->and($row->wholesale_pricing_method)->toBe('fixed')
            ->and($row->wholesale_markup_percentage)->toBeNull();
    })->with([
        'whole taka' => ['120.00', '100.00'],
        'decimal price' => ['2490.55', '1800.00'],
        'zero base cost' => ['120.00', '0.00'],
    ]);

    it('does not touch a variation’s price when its product is saved', function () {
        $id = preservationLegacyProduct('FWLEG0006', ['wholesale_price' => '300.00']);
        $product = Product::query()->findOrFail($id);
        $variant = ProductVariant::create([
            'product_id' => $id, 'sku' => 'FWLEG0006-M', 'combination_key' => 'm',
            'wholesale_price' => Money::fromDecimal('333.33'),
        ]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), preservationFormPost($product, ['name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        expect($variant->refresh()->wholesale_price?->toDecimal())->toBe('333.33');
    });

    it('does not change the database for a switch to Percentage until it is submitted; the preview writes nothing', function () {
        $id = preservationLegacyProduct('FWLEG0007', ['wholesale_price' => '120.00', 'base_cost' => '100.00']);
        $before = preservationSnapshot();

        $this->actingAs($this->manager)
            ->postJson(route('admin.catalog.products.price-preview'), ['base_cost' => '100.00', 'wholesale_markup_percentage' => '50'])
            ->assertOk()
            ->assertJsonPath('wholesale_price.amount', '150.00');

        expect(preservationSnapshot())->toBe($before)
            ->and(Product::query()->findOrFail($id)->wholesale_pricing_method)->toBe(WholesalePricingMethod::Fixed);
    });
});

describe('seeders', function () {
    it('does not overwrite the price of a product that already exists', function () {
        $id = preservationLegacyProduct('FAKE-PANJABI', ['wholesale_price' => '999.99']);

        $this->seed(FakeOrderSeeder::class);

        expect(DB::table('products')->where('id', $id)->value('wholesale_price'))->toBe('999.99');
    });
});

describe('deployment verification command', function () {
    it('reports every product Fixed and zero unexpected changes, and fails when a price moves', function () {
        preservationLegacyProduct('FWLEG0008', ['wholesale_price' => '120.00']);
        preservationLegacyProduct('FWLEG0009', ['wholesale_price' => '2490.55']);

        $file = tempnam(sys_get_temp_dir(), 'wpm');
        $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $file])->assertSuccessful();

        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])
            ->expectsTable(['Check', 'Count'], [
                ['Total existing products', 2],
                ['Products marked Fixed', 2],
                ['Products marked Percentage', 0],
                ['Products whose price or selling bounds changed unexpectedly (and variations)', 0],
                ['Products missing a wholesale price they previously had', 0],
            ])
            ->assertSuccessful();

        DB::table('products')->where('sku', 'FWLEG0009')->update(['wholesale_price' => '1.00']);

        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])->assertFailed();

        unlink($file);
    });
});
