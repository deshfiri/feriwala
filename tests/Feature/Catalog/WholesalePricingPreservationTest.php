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
 * all leave every typed price exactly as it was — compared as exact decimal
 * text straight from Postgres, never as a float.
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
 * @param  array<string, mixed>  $attributes
 */
function preservationLegacyVariant(int $productId, string $sku, array $attributes = []): int
{
    return DB::table('product_variants')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'product_id' => $productId,
        'sku' => $sku,
        'combination_key' => $sku,
        'currency_code' => 'BDT',
        'wholesale_price' => null,
        'base_cost' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

/**
 * A realistic pre-migration catalogue: integer and decimal prices, zero base
 * cost, null optional bounds, archived and trashed products, and a product
 * whose variations carry independent costs and prices.
 *
 * @return array<string, int>
 */
function preservationCatalogue(): array
{
    $ids = [];

    $ids['integer'] = preservationLegacyProduct('FWINT0001', ['wholesale_price' => '120.00', 'base_cost' => '100.00']);
    $ids['integer2'] = preservationLegacyProduct('FWINT0002', ['wholesale_price' => '5000.00', 'base_cost' => '4200.00']);
    $ids['decimal'] = preservationLegacyProduct('FWDEC0001', ['wholesale_price' => '2490.55', 'base_cost' => '1800.10']);
    $ids['decimal2'] = preservationLegacyProduct('FWDEC0002', ['wholesale_price' => '0.01', 'base_cost' => '0.01']);
    $ids['zero_cost'] = preservationLegacyProduct('FWZER0001', ['wholesale_price' => '350.00', 'base_cost' => '0.00']);
    $ids['zero_price'] = preservationLegacyProduct('FWZER0002', ['wholesale_price' => '0.00', 'base_cost' => '0.00']);
    $ids['null_bounds'] = preservationLegacyProduct('FWNUL0001', ['wholesale_price' => '999.90']);
    $ids['bounds'] = preservationLegacyProduct('FWBND0001', [
        'wholesale_price' => '2490.55',
        'minimum_selling_price' => '2500.10',
        'maximum_selling_price' => '3000.00',
        'suggested_selling_price' => '2750.25',
    ]);
    $ids['archived'] = preservationLegacyProduct('FWARC0001', ['wholesale_price' => '777.77', 'status' => 'archived']);
    $ids['trashed'] = preservationLegacyProduct('FWTRA0001', ['wholesale_price' => '888.88', 'deleted_at' => now()]);
    $ids['with_variants'] = preservationLegacyProduct('FWVAR0001', ['wholesale_price' => '2490.00', 'base_cost' => '1500.00']);

    $ids['v_both'] = preservationLegacyVariant($ids['with_variants'], 'FWVAR0001-M', ['wholesale_price' => '2600.75', 'base_cost' => '1900.00']);
    $ids['v_cost_only'] = preservationLegacyVariant($ids['with_variants'], 'FWVAR0001-L', ['base_cost' => '150.00']);
    $ids['v_price_only'] = preservationLegacyVariant($ids['with_variants'], 'FWVAR0001-XL', ['wholesale_price' => '999.99']);
    $ids['v_none'] = preservationLegacyVariant($ids['with_variants'], 'FWVAR0001-S');

    return $ids;
}

/**
 * Every protected value as Postgres stores it, as one comparable structure.
 *
 * @return array<string, mixed>
 */
function preservationDirect(): array
{
    $products = DB::select('select id, public_id, sku, base_cost::text b, wholesale_price::text w, minimum_selling_price::text mn, maximum_selling_price::text mx, suggested_selling_price::text sg from products order by id');
    $variants = DB::select('select id, product_id, sku, base_cost::text b, wholesale_price::text w from product_variants order by id');

    return [
        'products' => array_map(fn ($row) => (array) $row, $products),
        'variants' => array_map(fn ($row) => (array) $row, $variants),
        'checksum' => hash('sha256', json_encode([$products, $variants], JSON_THROW_ON_ERROR)),
    ];
}

function preservationSnapshotFile(): string
{
    return sys_get_temp_dir().'/wpm-'.Str::random(12).'.json';
}

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

describe('the migration', function () {
    it('leaves every protected value identical through migrate, an unrelated update, and rollback', function () {
        $migration = preservationMigration();
        $migration->down();

        $ids = preservationCatalogue();
        $before = preservationDirect();
        $file = preservationSnapshotFile();

        $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $file])->assertSuccessful();
        expect(decoct(fileperms($file) & 0777))->toBe('600');

        // 3. The migration.
        $migration->up();

        expect(preservationDirect())->toBe($before);
        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])->assertSuccessful();
        expect(DB::table('products')->where('wholesale_pricing_method', '!=', 'fixed')->count())->toBe(0)
            ->and(DB::table('products')->whereNotNull('wholesale_markup_percentage')->count())->toBe(0)
            ->and(DB::table('product_variants')->where('wholesale_pricing_method', '!=', 'fixed')->count())->toBe(0);

        // 5. Ordinary, unrelated edits: a rename, a description, a category
        // move, and a variation's availability toggled as the screen sends it.
        foreach (['integer', 'decimal', 'zero_cost', 'null_bounds', 'bounds', 'with_variants'] as $key) {
            $product = Product::query()->findOrFail($ids[$key]);

            $this->actingAs($this->manager)
                ->patch(route('admin.catalog.products.update', $product->public_id), preservationFormPost($product, [
                    'name' => "Renamed {$key}",
                    'description' => 'New description',
                    'category_id' => Category::query()->firstOrCreate(['name' => 'Other'])->public_id,
                ]))
                ->assertSessionHasNoErrors();
        }

        $parent = Product::query()->findOrFail($ids['with_variants']);

        foreach (['v_both', 'v_cost_only', 'v_price_only', 'v_none'] as $key) {
            $variant = ProductVariant::query()->findOrFail($ids[$key]);

            $this->actingAs($this->manager)
                ->patch(route('admin.catalog.products.variants.update', [$parent->public_id, $variant->public_id]), [
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'wholesale_price' => $variant->wholesale_price?->toDecimal() ?? '',
                    'base_cost' => $variant->base_cost?->toDecimal() ?? '',
                    'wholesale_pricing_method' => $variant->wholesale_pricing_method->value,
                    'wholesale_markup_percentage' => '',
                    'is_active' => '0',
                ])
                ->assertSessionHasNoErrors();
        }

        expect(preservationDirect())->toBe($before);
        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])->assertSuccessful();

        // 7. Rollback drops only the new columns.
        $migration->down();

        expect(Schema::hasColumn('products', 'wholesale_pricing_method'))->toBeFalse()
            ->and(preservationDirect())->toBe($before);
        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])->assertSuccessful();

        $migration->up();
        unlink($file);
    });

    it('shows a legacy product as Fixed with its exact price, zero base cost included', function () {
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

    it('contains no statement that writes a price', function () {
        $source = (string) file_get_contents(database_path('migrations/2026_11_11_100000_add_wholesale_pricing_method_to_products.php'));

        expect($source)->not->toMatch('/\bUPDATE\b/i')
            ->and($source)->not->toMatch('/->update\(/')
            ->and($source)->not->toMatch('/\bDROP\s+TABLE\b|dropIfExists|->change\(\)|ALTER\s+COLUMN/i')
            ->and($source)->not->toMatch('/wholesale_price[\'"`]?\)?->default|wholesale_price.*DEFAULT/i');
    });
});

describe('opening and resaving an existing product', function () {
    it('keeps the price exactly when saved untouched or when an unrelated field changes', function (string $price, string $cost) {
        $id = preservationLegacyProduct('FWLEG0005', ['wholesale_price' => $price, 'base_cost' => $cost]);
        $product = Product::query()->findOrFail($id);

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
        $before = preservationDirect();

        $this->actingAs($this->manager)
            ->postJson(route('admin.catalog.products.price-preview'), ['base_cost' => '100.00', 'wholesale_markup_percentage' => '50'])
            ->assertOk()
            ->assertJsonPath('wholesale_price.amount', '150.00');

        expect(preservationDirect())->toBe($before)
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

describe('the verification command', function () {
    it('passes on an untouched catalogue and keeps its output free of prices', function () {
        preservationCatalogue();
        $file = preservationSnapshotFile();

        $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $file])->assertSuccessful();
        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])
            ->expectsOutputToContain('Unexpected changes: 0')
            ->assertSuccessful();

        unlink($file);
    });

    it('fails, naming the row and category, for every kind of change to a protected value', function (string $category, Closure $mutate) {
        $ids = preservationCatalogue();
        $file = preservationSnapshotFile();
        $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $file])->assertSuccessful();

        $mutate($ids);

        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])
            ->expectsOutputToContain(strtoupper($category))
            ->assertFailed();

        unlink($file);
    })->with([
        'a changed price' => ['price changed', fn (array $ids) => DB::table('products')->where('id', $ids['integer'])->update(['wholesale_price' => '121.00'])],
        'a non-zero price becoming zero' => ['non-zero became zero', fn (array $ids) => DB::table('products')->where('id', $ids['decimal'])->update(['wholesale_price' => '0.00'])],
        'a variation price becoming null' => ['new null', fn (array $ids) => DB::table('product_variants')->where('id', $ids['v_both'])->update(['wholesale_price' => null])],
        'a changed minimum selling price' => ['bound changed', fn (array $ids) => DB::table('products')->where('id', $ids['bounds'])->update(['minimum_selling_price' => '1.00'])],
        'a nulled suggested price' => ['bound changed', fn (array $ids) => DB::table('products')->where('id', $ids['bounds'])->update(['suggested_selling_price' => null])],
        'a changed base cost' => ['price changed', fn (array $ids) => DB::table('products')->where('id', $ids['zero_cost'])->update(['base_cost' => '5.00'])],
        'a missing product' => ['missing row', fn (array $ids) => DB::table('products')->where('id', $ids['integer2'])->delete()],
        'a missing variation' => ['missing row', fn (array $ids) => DB::table('product_variants')->where('id', $ids['v_none'])->delete()],
        'a renumbered SKU' => ['identity changed', fn (array $ids) => DB::table('products')->where('id', $ids['integer'])->update(['sku' => 'FWCHANGED'])],
        'a decimal precision change' => ['decimal scale changed', function () {
            DB::statement('ALTER TABLE products ALTER COLUMN wholesale_price TYPE numeric(19,3)');
        }],
    ]);

    it('treats a trashed product as protected too', function () {
        $ids = preservationCatalogue();
        $file = preservationSnapshotFile();
        $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $file])->assertSuccessful();

        DB::table('products')->where('id', $ids['trashed'])->update(['wholesale_price' => '1.00']);

        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])->assertFailed();
        unlink($file);
    });

    it('allows a deliberate Percentage switch only when asked, and still fails on any other change', function () {
        $ids = preservationCatalogue();
        $file = preservationSnapshotFile();
        $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $file])->assertSuccessful();

        DB::table('products')->where('id', $ids['integer'])->update([
            'wholesale_pricing_method' => 'percentage', 'wholesale_markup_percentage' => '20.00', 'wholesale_price' => '120.00',
        ]);
        DB::table('products')->where('id', $ids['integer'])->update(['wholesale_price' => '150.00']);

        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file])->assertFailed();
        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file, '--allow-explicit-percentage' => true])->assertSuccessful();

        DB::table('products')->where('id', $ids['decimal'])->update(['wholesale_price' => '1.00']);
        $this->artisan('catalog:verify-wholesale-pricing', ['--against' => $file, '--allow-explicit-percentage' => true])->assertFailed();

        unlink($file);
    });

    it('refuses to write a snapshot inside the repository, public or storage', function () {
        foreach ([base_path('wpm-snapshot.json'), public_path('wpm-snapshot.json'), storage_path('wpm-snapshot.json')] as $inside) {
            $this->artisan('catalog:verify-wholesale-pricing', ['--snapshot' => $inside])->assertFailed();
            expect(file_exists($inside))->toBeFalse();
        }
    });
});
