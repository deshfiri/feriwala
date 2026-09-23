<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Central fields refused where a request does not own them (P3-17, §12).
 *
 * §12 names what may not be modified: the central SKU, central stock, the
 * central wholesale price and locked product information. A business account
 * never reaches a catalogue write (P3-15); among the requests an authorised
 * editor can send, each protected field is accepted by exactly one endpoint and
 * refused by name everywhere else — never silently dropped, so a crafted request
 * cannot look as though it might have worked.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Kitchen']);
    $this->product = Product::create([
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => $this->category->id,
        'base_cost_minor' => 180000,
        'wholesale_price_minor' => 210000,
    ]);
});

/**
 * A valid product form submission, overridable field by field.
 *
 * @return array<string, mixed>
 */
function centralFieldsProductPayload(array $overrides = []): array
{
    return [
        'name' => 'Rice cooker',
        'sku' => 'FW-RC',
        'category_id' => Category::query()->value('public_id'),
        'base_cost_minor' => '1800.00',
        'wholesale_price_minor' => '2100.00',
        ...$overrides,
    ];
}

/**
 * The product exactly as it stood before a refused request.
 *
 * @return array<string, mixed>
 */
function centralFieldsSnapshot(Product $product): array
{
    $product->refresh();

    return [
        'sku' => $product->sku,
        'status' => $product->status,
        'wholesale_price_minor' => $product->wholesale_price_minor->minorUnits,
        'base_cost_minor' => $product->base_cost_minor->minorUnits,
        'currency_code' => $product->currency_code,
        'public_id' => $product->public_id,
        'is_featured' => $product->is_featured,
        'wholesale_status' => $product->wholesale_status,
        'dropshipping_status' => $product->dropshipping_status,
        'package_scope' => $product->package_scope,
    ];
}

describe('the product form', function () {
    it('refuses every central field it does not own, by name, and changes nothing', function (string $field, mixed $value) {
        $before = centralFieldsSnapshot($this->product);

        $this->actingAs($this->manager)
            ->patch(
                route('admin.catalog.products.update', $this->product->public_id),
                centralFieldsProductPayload(['name' => 'Renamed', $field => $value]),
            )
            ->assertSessionHasErrors($field);

        expect(centralFieldsSnapshot($this->product))->toBe($before)
            ->and($this->product->name)->toBe('Rice cooker');
    })->with([
        'lifecycle status' => ['status', 'active'],
        'lifecycle status, even empty' => ['status', null],
        'dropshipping channel' => ['dropshipping_status', 'dropshipping_enabled'],
        'wholesale channel' => ['wholesale_status', 'wholesale_enabled'],
        'featuring' => ['is_featured', true],
        'first publication' => ['published_at', '2026-01-01 00:00:00'],
        'package eligibility' => ['package_scope', 'all'],
        'account eligibility' => ['account_scope', 'any'],
        'currency' => ['currency_code', 'USD'],
        'public identifier' => ['public_id', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        'row identifier' => ['id', 999],
        'quantity pricing' => ['tiers', [['min_quantity' => 10, 'unit_price_minor' => 1]]],
        'stock' => ['stock', 50],
        'available stock' => ['available_stock', 50],
        'stock quantity' => ['stock_quantity', 50],
    ]);

    it('still changes the SKU and both figures it owns', function () {
        $this->actingAs($this->manager)
            ->patch(
                route('admin.catalog.products.update', $this->product->public_id),
                centralFieldsProductPayload(['sku' => 'FW-RC-2', 'wholesale_price_minor' => '1990.00']),
            )
            ->assertSessionHasNoErrors();

        expect($this->product->refresh()->sku)->toBe('FW-RC-2')
            ->and($this->product->wholesale_price_minor->minorUnits)->toBe(199000);
    });

    it('refuses the same fields when creating, so a new product cannot start live', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.store'), centralFieldsProductPayload([
                'sku' => 'FW-NEW',
                'status' => 'active',
                'wholesale_status' => 'wholesale_enabled',
            ]))
            ->assertSessionHasErrors(['status', 'wholesale_status']);

        expect(Product::query()->where('sku', 'FW-NEW')->exists())->toBeFalse();
    });

    it('names the refusal for a JSON caller, stock separately, in the reader\'s language', function () {
        $url = route('admin.catalog.products.update', $this->product->public_id);

        $this->actingAs($this->manager)
            ->patchJson($url, centralFieldsProductPayload(['status' => 'active', 'stock' => 5]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', __('catalog.restrictions.not_here', ['attribute' => 'status'], 'en'))
            ->assertJsonPath('errors.stock.0', __('catalog.restrictions.stock', [], 'en'));

        $this->manager->forceFill(['locale' => 'bn'])->save();

        $this->actingAs($this->manager)
            ->patchJson($url, centralFieldsProductPayload(['stock' => 5]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.stock.0', __('catalog.restrictions.stock', [], 'bn'));
    });

    it('refuses a stock key whose name carries a dot', function () {
        $this->actingAs($this->manager)
            ->patchJson(
                route('admin.catalog.products.update', $this->product->public_id),
                centralFieldsProductPayload(['name' => 'Renamed', 'stock.level' => 5]),
            )
            ->assertUnprocessable();

        expect($this->product->refresh()->name)->toBe('Rice cooker');
    });
});

describe('a variation', function () {
    it('cannot be moved to another product, given another combination, or given stock', function () {
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'FW-RC-L', 'combination_key' => 'k']);
        $other = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $this->category->id]);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.variants.update', [$this->product->public_id, $variant->public_id]), [
                'sku' => 'FW-RC-L',
                'product_id' => $other->id,
                'combination_key' => 'other',
                'stock_quantity' => 3,
            ])
            ->assertSessionHasErrors(['product_id', 'combination_key', 'stock_quantity']);

        expect($variant->refresh()->product_id)->toBe($this->product->id)
            ->and($variant->combination_key)->toBe('k');
    });
});

describe('every other product endpoint owns only its own field', function () {
    it('refuses a price on the lifecycle endpoint, and makes no move', function () {
        $before = centralFieldsSnapshot($this->product);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.status.update', $this->product->public_id), [
                'status' => ProductStatus::PendingReview->value,
                'wholesale_price_minor' => 1,
            ])
            ->assertSessionHasErrors('wholesale_price_minor');

        expect(centralFieldsSnapshot($this->product))->toBe($before);
    });

    it('refuses a channel column on the channel endpoint, whose channel is in the address', function () {
        $before = centralFieldsSnapshot($this->product);

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.channels.update', ['product' => $this->product->public_id, 'channel' => 'wholesale']), [
                'enabled' => true,
                'dropshipping_status' => 'dropshipping_enabled',
            ])
            ->assertSessionHasErrors('dropshipping_status');

        expect(centralFieldsSnapshot($this->product))->toBe($before);
    });

    it('refuses a price or a SKU in a bulk action, and features nothing', function () {
        $before = centralFieldsSnapshot($this->product);

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.bulk'), [
                'products' => [$this->product->public_id],
                'action' => 'feature',
                'enable' => true,
                'wholesale_price_minor' => 1,
                'sku' => 'HIJACKED',
            ])
            ->assertSessionHasErrors(['wholesale_price_minor', 'sku']);

        expect(centralFieldsSnapshot($this->product))->toBe($before);
    });

    it('refuses the lifecycle on the eligibility, related and featuring endpoints', function () {
        $before = centralFieldsSnapshot($this->product);
        $id = $this->product->public_id;

        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.eligibility.update', $id), [
                'package_scope' => 'all',
                'account_scope' => 'any',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.related.update', $id), ['related_ids' => [], 'status' => 'active'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->manager)
            ->patch(route('admin.catalog.products.featured.update', $id), ['featured' => true, 'wholesale_price_minor' => 1])
            ->assertSessionHasErrors('wholesale_price_minor');

        expect(centralFieldsSnapshot($this->product))->toBe($before);
    });

    it('refuses the base figures on the quantity pricing endpoint, which owns only the bands', function () {
        $this->actingAs($this->manager)
            ->put(route('admin.catalog.products.price-tiers.update', $this->product->public_id), [
                'variant_id' => null,
                'tiers' => [['min_quantity' => 10, 'unit_price_minor' => 200000]],
                'wholesale_price_minor' => 1,
            ])
            ->assertSessionHasErrors('wholesale_price_minor');

        expect($this->product->refresh()->wholesale_price_minor->minorUnits)->toBe(210000)
            ->and($this->product->priceTiers()->count())->toBe(0);
    });

    it('refuses central fields and stock on the media and variation-builder endpoints', function () {
        Storage::fake('public');
        $id = $this->product->public_id;

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.store', $id), [
                'file' => UploadedFile::fake()->image('front.png', 400, 400),
                'sku' => 'HIJACKED',
            ])
            ->assertSessionHasErrors('sku');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.media.reorder', $id), ['order' => ['x'], 'status' => 'active'])
            ->assertSessionHasErrors('status');

        $this->actingAs($this->manager)
            ->post(route('admin.catalog.products.variants.generate', $id), ['values' => ['x'], 'stock' => 5])
            ->assertSessionHasErrors('stock');

        expect(ProductMedia::query()->count())->toBe(0)
            ->and($this->product->refresh()->sku)->toBe('FW-RC');
    });
});

describe('authorisation still answers first', function () {
    it('gives a partner a 403, never a list of which fields were protected', function () {
        $owner = testBusinessAccount(AccountStatus::Active)->owner;

        $this->actingAs($owner)
            ->patchJson(route('admin.catalog.products.status.update', $this->product->public_id), [
                'status' => 'active',
                'wholesale_price_minor' => 1,
            ])
            ->assertForbidden()
            ->assertJsonMissingPath('errors');

        $this->actingAs($owner)
            ->patchJson(route('admin.catalog.products.update', $this->product->public_id), centralFieldsProductPayload(['stock' => 5]))
            ->assertForbidden()
            ->assertJsonMissingPath('errors');
    });
});
