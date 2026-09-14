<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Stock items: one stockable unit in one warehouse (P3-22, §19).
 *
 * A product without variations is stocked as itself, a product with variations
 * per variation. Six buckets, none below zero; one row per SKU per warehouse;
 * and what a row is about never changes once written.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::InventoryManager);
    $this->category = Category::create(['name' => 'Kitchen']);
    $this->kettle = Product::create(['name' => 'Kettle', 'sku' => 'FW-KT', 'category_id' => $this->category->id]);
    $this->shirt = Product::create(['name' => 'Panjabi', 'sku' => 'FW-PJ', 'category_id' => $this->category->id]);
    $this->medium = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-PJ-M', 'combination_key' => 'm']);
    $this->large = ProductVariant::create(['product_id' => $this->shirt->id, 'sku' => 'FW-PJ-L', 'combination_key' => 'l']);
    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
});

/**
 * @return array<string, string|null>
 */
function inventoryTrackPayload(Warehouse $warehouse, Product $product, ?ProductVariant $variant = null): array
{
    return [
        'warehouse' => $warehouse->public_id,
        'product' => $product->public_id,
        'variant' => $variant?->public_id,
    ];
}

describe('holding a SKU in a warehouse', function () {
    it('holds a product without variations at zero in every bucket, and audits it', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->kettle))
            ->assertSessionHasNoErrors();

        $item = StockItem::query()->sole();

        expect($item->product_id)->toBe($this->kettle->id)
            ->and($item->product_variant_id)->toBeNull()
            ->and($item->buckets())->toBe(['available' => 0, 'reserved' => 0, 'processing' => 0, 'sold' => 0, 'returned' => 0, 'damaged' => 0, 'allocated' => 0])
            ->and(AuditLog::query()->where('action', 'inventory.stock_tracked')->exists())->toBeTrue();
    });

    it('holds a product with variations per variation, and refuses the product itself', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->shirt))
            ->assertSessionHasErrors(['product' => __('inventory.refused.choose_variation')]);

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->shirt, $this->medium))
            ->assertSessionHasNoErrors();

        expect(StockItem::query()->sole()->product_variant_id)->toBe($this->medium->id);
    });

    it('refuses a variation of another product, a SKU already held there, and a switched-off warehouse', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->kettle, $this->medium))
            ->assertSessionHasErrors(['product' => __('inventory.refused.variation_not_of_product')]);

        StockItem::create(['warehouse_id' => $this->dhaka->id, 'product_id' => $this->kettle->id]);

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->kettle))
            ->assertSessionHasErrors(['product' => __('inventory.refused.already_tracked')]);

        $closed = Warehouse::create(['code' => 'OLD', 'name' => 'Old depot', 'is_active' => false]);

        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($closed, $this->shirt, $this->large))
            ->assertSessionHasErrors(['warehouse' => __('inventory.refused.warehouse_inactive')]);

        expect(StockItem::query()->count())->toBe(1);
    });

    it('holds the same SKU in a second warehouse', function () {
        $chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram']);

        $this->actingAs($this->manager)->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->kettle));
        $this->actingAs($this->manager)
            ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($chattogram, $this->kettle))
            ->assertSessionHasNoErrors();

        expect(StockItem::query()->where('product_id', $this->kettle->id)->count())->toBe(2);
    });

    it('refuses a partner and staff who may only view', function () {
        $partner = testBusinessAccount(AccountStatus::Active)->owner;
        $partner->givePermissionTo(['inventory.view', 'inventory.edit']);

        foreach ([$partner, testPlatformStaff(PlatformRole::ProductManager)] as $user) {
            $this->actingAs($user)
                ->post(route('admin.inventory.stock.store'), inventoryTrackPayload($this->dhaka, $this->kettle))
                ->assertForbidden();
        }

        $this->actingAs($partner)->get(route('admin.inventory.stock.index'))->assertForbidden();

        expect(StockItem::query()->count())->toBe(0);
    });
});

describe('the database', function () {
    beforeEach(function () {
        $this->item = StockItem::create(['warehouse_id' => $this->dhaka->id, 'product_id' => $this->kettle->id]);
    });

    it('refuses a bucket below zero', function (string $bucket) {
        expect(fn () => DB::table('stock_items')->where('id', $this->item->id)->update([$bucket => -1]))
            ->toThrow(QueryException::class, 'stock_items_buckets_not_negative');
    })->with(['available', 'reserved', 'processing', 'sold', 'returned', 'damaged']);

    it('refuses a variation that is not of the product', function () {
        expect(fn () => StockItem::create([
            'warehouse_id' => $this->dhaka->id,
            'product_id' => $this->kettle->id,
            'product_variant_id' => $this->medium->id,
        ]))->toThrow(QueryException::class, 'is not a variation of product');
    });

    it('refuses a second row for the same SKU in the same warehouse', function () {
        expect(fn () => StockItem::create(['warehouse_id' => $this->dhaka->id, 'product_id' => $this->kettle->id]))
            ->toThrow(QueryException::class, 'stock_items_product_unique');
    });

    it('refuses moving an item to another warehouse or product', function (string $column) {
        $values = [
            'warehouse_id' => Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram'])->id,
            'product_id' => $this->shirt->id,
        ];

        expect(fn () => DB::table('stock_items')->where('id', $this->item->id)->update([$column => $values[$column]]))
            ->toThrow(QueryException::class, "stock_items.{$column} cannot be changed once written");
    })->with(['warehouse_id', 'product_id']);
});

describe('the stock list', function () {
    beforeEach(function () {
        $chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram']);

        StockItem::create(['warehouse_id' => $this->dhaka->id, 'product_id' => $this->kettle->id, 'available' => 12]);
        StockItem::create(['warehouse_id' => $chattogram->id, 'product_id' => $this->shirt->id, 'product_variant_id' => $this->medium->id]);
    });

    it('lists each SKU per warehouse with its six figures and no price', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/inventory/stock')
                ->has('items.data', 2)
                ->where('items.data.0.sku', 'FW-KT')
                ->where('items.data.0.warehouse.code', 'DHK')
                ->where('items.data.0.buckets.available', 12)
                ->where('items.data.1.sku', 'FW-PJ-M')
                ->missing('items.data.0.wholesale_price')
                ->missing('items.data.0.base_cost')
                ->has('warehouses', 2)
                ->where('can.track', true));
    });

    it('filters by warehouse, stock state and SKU in the database, and ignores values it does not know', function () {
        $filter = fn (array $query) => $this->actingAs($this->manager)->get(route('admin.inventory.stock.index', $query));

        $filter(['warehouse' => $this->dhaka->public_id])->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'FW-KT'));
        $filter(['state' => 'out_of_stock'])->assertInertia(fn (Assert $page) => $page->has('items.data', 1)->where('items.data.0.sku', 'FW-PJ-M'));
        $filter(['search' => 'pj-m'])->assertInertia(fn (Assert $page) => $page->has('items.data', 1));
        $filter(['state' => 'drop table', 'sort' => 'password'])->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 2)
            ->where('filters.state', null)
            ->where('filters.sort', null));
    });

    it('shows staff who may only view the list without the ability to hold a SKU', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::ProductManager))
            ->get(route('admin.inventory.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.track', false));
    });

    it('finds stockable units for the hold dialog, never a product that has variations', function () {
        $this->actingAs($this->manager)
            ->get(route('admin.inventory.stock.index', ['unit_search' => 'FW']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('units')
                ->reloadOnly('units', fn (Assert $reload) => $reload
                    ->has('units', 3)
                    ->where('units', fn ($units) => collect($units)->pluck('sku')->sort()->values()->all() === ['FW-KT', 'FW-PJ-L', 'FW-PJ-M'])));
    });
});
