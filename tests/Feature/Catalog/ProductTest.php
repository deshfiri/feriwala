<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\ProductBarcode;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Central products (P3-3, §11.1, §12).
 *
 * The §12 hard requirement is the first thing this file holds: a regular user
 * cannot create or modify a product, and that is a refusal at every endpoint
 * rather than a missing button. After it, the constraints that have to hold even
 * when a request never passed through the form — the SKU unique whatever its
 * casing, prices never negative, a category or brand never removed from under
 * the products filed against it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Electronics']);
    $this->brand = Brand::create(['name' => 'Walton']);
});

function catalogProduct(array $attributes = []): Product
{
    return Product::create([
        'name' => 'Walton Rice Cooker',
        'sku' => 'FW-RC-1',
        'category_id' => Category::query()->value('id'),
        'base_cost' => Money::fromDecimal('1800.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2100.00', Currency::BDT),
        ...$attributes,
    ]);
}

/**
 * A valid form submission, overridable field by field.
 *
 * @return array<string, mixed>
 */
function catalogProductPayload(array $overrides = []): array
{
    return [
        'name' => 'Walton Rice Cooker 2.8L',
        'sku' => 'fw-rc-28',
        'barcode' => '8941100500012',
        'short_description' => 'Non-stick, 2.8 litre.',
        'description' => 'A rice cooker.',
        'category_id' => Category::query()->value('public_id'),
        'brand_id' => Brand::query()->value('public_id'),
        'base_cost' => '1800.00',
        'wholesale_price' => '2490.00',
        ...$overrides,
    ];
}

describe('only the platform writes products (§12)', function () {
    it('refuses a business account holder at every endpoint', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;
        $product = catalogProduct();

        $this->actingAs($owner)->get(route('admin.catalog.products.index'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.catalog.products.create'))->assertForbidden();
        $this->actingAs($owner)->get(route('admin.catalog.products.edit', $product->public_id))->assertForbidden();
        $this->actingAs($owner)
            ->post(route('admin.catalog.products.store'), catalogProductPayload())
            ->assertForbidden();
        $this->actingAs($owner)
            ->patch(route('admin.catalog.products.update', $product->public_id), catalogProductPayload(['wholesale_price' => '0.01']))
            ->assertForbidden();
        $this->actingAs($owner)
            ->delete(route('admin.catalog.products.destroy', $product->public_id))
            ->assertForbidden();

        expect(Product::query()->count())->toBe(1)
            ->and($product->refresh()->wholesale_price->toDecimal())->toBe('2100.00');
    });

    it('lets staff who may read the catalogue see a product but not change it', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $product = catalogProduct();

        $this->actingAs($viewer)->get(route('admin.catalog.products.index'))->assertOk();
        $this->actingAs($viewer)
            ->get(route('admin.catalog.products.edit', $product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.edit', false)->where('can.delete', false));

        $this->actingAs($viewer)->get(route('admin.catalog.products.create'))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('admin.catalog.products.store'), catalogProductPayload())
            ->assertForbidden();
        $this->actingAs($viewer)
            ->patch(route('admin.catalog.products.update', $product->public_id), catalogProductPayload(['name' => 'Renamed']))
            ->assertForbidden();
        $this->actingAs($viewer)
            ->delete(route('admin.catalog.products.destroy', $product->public_id))
            ->assertForbidden();

        expect($product->refresh()->name)->toBe('Walton Rice Cooker');
    });

    it('refuses staff with no catalogue permission at all', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SmsManager))
            ->get(route('admin.catalog.products.index'))
            ->assertForbidden();
    });

    it('lets a product manager create a draft, with money held as an exact flat-Taka decimal', function () {
        $response = $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload());

        $product = Product::query()->firstOrFail();

        $response->assertRedirect(route('admin.catalog.products.edit', $product->public_id));

        expect($product->sku)->toBe('FW-RC-28')
            ->and($product->status)->toBe(ProductStatus::Draft)
            ->and($product->currency_code)->toBe('BDT')
            ->and($product->category_id)->toBe($this->category->id)
            ->and($product->brand_id)->toBe($this->brand->id)
            ->and($product->wholesale_price)->toBeInstanceOf(Money::class)
            ->and($product->wholesale_price->toDecimal())->toBe('2490.00')
            ->and($product->base_cost->toDecimal())->toBe('1800.00');
    });

    it('converts Taka form input to an exact flat-Taka decimal', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload([
                'sku' => 'fw-rc-taka',
                'wholesale_price' => '2490.50',
                'base_cost' => '1800.25',
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('sku', 'FW-RC-TAKA')->firstOrFail();

        expect($product->wholesale_price->toDecimal())->toBe('2490.50')
            ->and($product->base_cost->toDecimal())->toBe('1800.25');
    });
});

describe('validation and database constraints', function () {
    it('requires a name, a category and both figures, but invents a BPC and a barcode', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), [])
            ->assertSessionHasErrors(['name', 'category_id', 'base_cost', 'wholesale_price'])
            ->assertSessionDoesntHaveErrors(['sku', 'barcode']);
    });

    it('generates a BPC and a 13-digit barcode when both are left blank', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload([
                'sku' => '',
                'barcode' => '',
            ]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->firstOrFail();

        expect($product->sku)->toMatch('/^[A-Z0-9][A-Z0-9._-]*$/')
            ->and($product->barcode)->toMatch('/^\d{13}$/')
            ->and((int) $product->barcode[12])->toBe(
                ProductBarcode::checkDigit(substr($product->barcode, 0, 12)),
            );
    });

    it('refuses a price with more than two decimal places, or that is negative', function () {
        // Refused rather than rounded: a silently rounded price is a wrong price
        // nobody noticed.
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload([
                'wholesale_price' => '2490.505',
                'base_cost' => '-1',
            ]))
            ->assertSessionHasErrors(['wholesale_price', 'base_cost']);

        expect(Product::query()->count())->toBe(0);
    });

    it('refuses a SKU another product holds, whatever the casing', function () {
        catalogProduct(['sku' => 'FW-RC-28']);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload(['sku' => 'fw-rc-28']))
            ->assertSessionHasErrors('sku');
    });

    it('refuses SKU characters a label printer and a spreadsheet would disagree about', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload(['sku' => 'FW RC/28!']))
            ->assertSessionHasErrors('sku');
    });

    /*
     * One violation per test: Postgres aborts the surrounding transaction on
     * the first, so a second statement in the same test would fail for that
     * reason rather than its own.
     */
    it('holds the SKU unique in the database', function () {
        catalogProduct(['sku' => 'FW-RC-28']);

        expect(fn () => catalogProduct(['sku' => 'FW-RC-28', 'slug' => 'another']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('holds the SKU upper-case in the database, which makes it unique whatever the casing', function () {
        expect(fn () => catalogProduct(['sku' => 'fw-other']))
            ->toThrow(QueryException::class, 'products_sku_upper');
    });

    it('refuses a negative figure in the database, not only in the form', function () {
        expect(fn () => catalogProduct(['wholesale_price' => Money::fromDecimal('-1.00', Currency::BDT)]))
            ->toThrow(QueryException::class, 'products_wholesale_price_not_negative');
    });

    it('holds a barcode unique when present, and allows any number without one', function () {
        catalogProduct(['sku' => 'FW-1', 'barcode' => '8941100500012']);
        catalogProduct(['sku' => 'FW-2', 'barcode' => null]);
        catalogProduct(['sku' => 'FW-3', 'barcode' => null]);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload(['barcode' => '8941100500012']))
            ->assertSessionHasErrors('barcode');

        expect(fn () => catalogProduct(['sku' => 'FW-4', 'barcode' => '8941100500012']))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a category or brand it cannot find', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload([
                'category_id' => 'not-a-category',
                'brand_id' => (string) $this->brand->id,
            ]))
            ->assertSessionHasErrors(['category_id', 'brand_id']);
    });

    it('allows a product without a brand', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), catalogProductPayload(['brand_id' => null]))
            ->assertSessionHasNoErrors();

        expect(Product::query()->firstOrFail()->brand_id)->toBeNull();
    });
});

describe('editing', function () {
    it('saves changes and keeps the slug when the product is renamed', function () {
        $product = catalogProduct();
        $slug = $product->slug;

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), catalogProductPayload([
                'name' => 'Walton Rice Cooker (new model)',
                'sku' => 'FW-RC-1',
                'wholesale_price' => '1990.00',
            ]))
            ->assertSessionHasNoErrors();

        $product->refresh();

        expect($product->name)->toBe('Walton Rice Cooker (new model)')
            ->and($product->slug)->toBe($slug)
            ->and($product->wholesale_price->toDecimal())->toBe('1990.00');
    });

    it('lets a product keep its own SKU when saved', function () {
        $product = catalogProduct(['sku' => 'FW-RC-1']);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.update', $product->public_id), catalogProductPayload(['sku' => 'fw-rc-1']))
            ->assertSessionHasNoErrors();
    });
});

describe('deleting', function () {
    it('deletes a draft', function () {
        $product = catalogProduct();

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.destroy', $product->public_id))
            ->assertRedirect(route('admin.catalog.products.index'));

        expect(Product::query()->count())->toBe(0);
    });

    it('refuses to delete a product that has left draft', function () {
        $product = catalogProduct(['status' => 'active']);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.destroy', $product->public_id))
            ->assertSessionHasErrors('product');

        expect(Product::query()->count())->toBe(1);
    });

    it('refuses to delete a draft that has already been through review, even once it is back to draft', function () {
        // Archived is the only status that returns to Draft (§11.2), and it
        // arrives with history — a draft in that shape is not the same thing
        // as one that has never left the author's hands.
        $product = catalogProduct(['status' => 'draft']);

        $product->statusHistory()->create([
            'axis' => ProductStatus::AXIS_LIFECYCLE,
            'from_status' => ProductStatus::Archived,
            'to_status' => ProductStatus::Draft,
        ]);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.destroy', $product->public_id))
            ->assertSessionHasErrors('product');

        expect(Product::query()->count())->toBe(1);
    });
});

describe('categories and brands keep their products (§11.3)', function () {
    it('refuses to remove a category that holds a product, at the action and in the database', function () {
        catalogProduct();

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.categories.destroy', $this->category->public_id))
            ->assertSessionHasErrors('category');

        expect(fn () => Category::query()->whereKey($this->category->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses to remove a brand that is on a product', function () {
        catalogProduct(['brand_id' => $this->brand->id]);

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.brands.destroy', $this->brand->public_id))
            ->assertSessionHasErrors('brand');

        expect(Brand::query()->count())->toBe(1);
    });

    it('counts the products on the category and brand screens', function () {
        catalogProduct(['brand_id' => $this->brand->id]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.categories.index'))
            ->assertInertia(fn (Assert $page) => $page->where('categories.0.products_count', 1));

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.brands.index'))
            ->assertInertia(fn (Assert $page) => $page->where('brands.data.0.products_count', 1));
    });
});

describe('the screens', function () {
    it('lists products, searched in the database by SKU, with the price rendered by the server', function () {
        catalogProduct(['name' => 'Rice cooker', 'sku' => 'FW-RC-1']);
        catalogProduct(['name' => 'Blender', 'sku' => 'FW-BL-9', 'slug' => 'blender']);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.index', ['search' => 'bl-9']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/products/index')
                ->has('products.data', 1)
                ->where('products.data.0.sku', 'FW-BL-9')
                ->where('products.data.0.wholesale_price.amount', '2100.00')
                ->where('products.data.0.wholesale_price.currency', 'BDT')
                ->where('can.create', true),
            );
    });

    it('opens the editor with the figures, their rendering, and the options', function () {
        $child = Category::create(['name' => 'Kitchen', 'parent_id' => $this->category->id]);
        $product = catalogProduct(['category_id' => $child->id, 'brand_id' => $this->brand->id]);

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.edit', $product->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/products/form')
                ->where('product.category_id', $child->public_id)
                ->where('product.brand_id', $this->brand->public_id)
                ->where('product.wholesale_price.amount', '2100.00')
                ->where('options.categories.1.label', 'Electronics › Kitchen')
                ->where('options.brands.0.label', 'Walton'),
            );
    });

    it('opens an empty editor to create one', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/catalog/products/form')
                ->where('product', null),
            );
    });
});

describe('downloading a barcode', function () {
    it('serves the EAN-13 as a downloadable SVG', function () {
        $product = catalogProduct(['barcode' => '8941100500012']);

        $response = $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.barcode', $product->public_id))
            ->assertOk();

        $response->assertHeader('content-type', 'image/svg+xml');
        expect($response->headers->get('content-disposition'))->toContain('attachment');
        expect($response->getContent())->toContain('<svg')->toContain('8941100500012');
    });

    it('has nothing to serve for a product without a barcode', function () {
        $product = catalogProduct();

        $this->actingAs($this->manager)
            ->get(route('admin.catalog.products.barcode', $product->public_id))
            ->assertNotFound();
    });

    it('lets a read-only viewer download it too', function () {
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);
        $product = catalogProduct(['barcode' => '8941100500012']);

        $this->actingAs($viewer)
            ->get(route('admin.catalog.products.barcode', $product->public_id))
            ->assertOk();
    });
});
