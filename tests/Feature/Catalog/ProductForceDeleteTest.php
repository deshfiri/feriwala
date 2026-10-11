<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Actions\ForceDeleteProduct;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Enums\StockAdjustmentKind;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Super Admin Force Delete of a Product with history behind it.
 *
 * What must survive is as much the point as what goes: stock movements, order
 * lines, status history and the audit trail stay, readable from their own
 * snapshots, and the append-only protection on them still holds.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
    $this->category = Category::create(['name' => 'Kitchen']);
});

/**
 * A trashed Discontinued product with stock, a stock movement and an order line.
 */
function forceDeleteTestProduct(object $test, OrderStatus $orderStatus = OrderStatus::Delivered): Product
{
    $product = Product::create([
        'name' => 'Old rice cooker',
        'sku' => 'FW-OLD-1',
        'category_id' => $test->category->id,
        'status' => ProductStatus::Discontinued,
        'base_cost' => Money::fromDecimal('1800.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2100.00', Currency::BDT),
    ]);

    $warehouse = Warehouse::create(['name' => 'Dhaka Depot', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
    $item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => null]);
    app(AdjustStock::class)->handle($test->superAdmin, $item, StockAdjustmentKind::Receive, 7, 'Opening balance for the test.');

    $order = Order::factory()->create(['status' => $orderStatus]);
    OrderItem::create([
        'order_id' => $order->id,
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => 1,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('1800.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('1800.00', Currency::BDT),
        'line_total' => Money::fromDecimal('1800.00', Currency::BDT),
        'created_at' => now(),
    ]);

    DB::table('product_status_history')->insert([
        'product_id' => $product->id, 'axis' => 'lifecycle', 'from_status' => 'active', 'to_status' => 'discontinued',
    ]);

    app(ManageProducts::class)->trash($test->superAdmin, $product->refresh(), 'Test product.');

    return $product->refresh();
}

describe('force deleting a product with history', function () {
    it('removes the product, zeroes its stock through the ledger and keeps every record readable', function () {
        $product = forceDeleteTestProduct($this);
        $publicId = $product->public_id;

        app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'FW-OLD-1');

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeFalse();

        // Stock: zeroed with a recorded adjustment, item kept, detached, snapshot readable.
        $item = StockItem::query()->first();
        expect($item->product_id)->toBeNull()
            ->and($item->available)->toBe(0)
            ->and($item->product_id_snapshot['sku'])->toBe('FW-OLD-1');
        $this->assertDatabaseHas('stock_adjustments', ['stock_item_id' => $item->id, 'kind' => 'remove', 'quantity' => 7]);

        // Movements: both the receipt and the zero-out remain, with snapshots.
        $movements = DB::table('stock_movements')->where('stock_item_id', $item->id)->get();
        expect($movements)->toHaveCount(2)
            ->and($movements->pluck('product_id')->filter()->all())->toBe([])
            ->and(json_decode($movements->first()->product_id_snapshot, true)['public_id'])->toBe($publicId);

        // Order line: kept with its own snapshot and the line's own SKU/name.
        $line = DB::table('order_items')->first();
        expect($line->product_id)->toBeNull()
            ->and($line->sku)->toBe('FW-OLD-1')
            ->and(json_decode($line->product_id_snapshot, true)['name'])->toBe('Old rice cooker');

        // Status history: kept, detached, stamped.
        $history = DB::table('product_status_history')->where('product_public_id', $publicId)->get();
        expect($history)->not->toBeEmpty()
            ->and($history->pluck('product_id')->filter()->all())->toBe([]);

        // Audit: one sensitive record naming the actor, reason and snapshot.
        $audit = DB::table('audit_logs')->where('action', 'catalog.product_force_deleted')->first();
        expect($audit)->not->toBeNull()
            ->and((bool) $audit->is_sensitive)->toBeTrue()
            ->and($audit->actor_id)->toBe($this->superAdmin->id)
            ->and($audit->reason)->toBe('Unwanted test product.');
    });

    it('keeps preserved history immutable after the product is gone', function () {
        $product = forceDeleteTestProduct($this);
        app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'FW-OLD-1');

        expect(fn () => DB::table('stock_movements')->update(['quantity' => 99]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_movements')->update(['product_id_snapshot' => json_encode(['sku' => 'X'])]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_movements')->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_items')->update(['product_name' => 'Renamed']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_items')->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('product_status_history')->update(['reason' => 'rewritten']))->toThrow(QueryException::class);
    });

    it('accepts the exact name as confirmation', function () {
        $product = forceDeleteTestProduct($this);

        app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'Old rice cooker');

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeFalse();
    });

    it('refuses a wrong confirmation, a thin reason and a product not in Trash', function () {
        $product = forceDeleteTestProduct($this);

        expect(fn () => app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'nope'))
            ->toThrow(CatalogRefused::class, 'confirm')
            ->and(fn () => app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'short', 'FW-OLD-1'))
            ->toThrow(CatalogRefused::class, 'reason');

        $product->restore();

        expect(fn () => app(ForceDeleteProduct::class)->handle($this->superAdmin, $product->refresh(), 'Unwanted test product.', 'FW-OLD-1'))
            ->toThrow(CatalogRefused::class, 'Trash');
    });

    it('refuses while an order for it is still in flight', function () {
        $product = forceDeleteTestProduct($this, OrderStatus::Processing);

        expect(fn () => app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'FW-OLD-1'))
            ->toThrow(CatalogRefused::class, 'in flight');

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();
    });

    it('needs the Super Admin password, a reason and the typed confirmation over HTTP', function () {
        $product = forceDeleteTestProduct($this);
        $url = route('admin.catalog.products.trash.force-delete', $product->public_id);

        $this->actingAs($this->superAdmin)
            ->delete($url, ['password' => 'wrong-password', 'reason' => 'Unwanted test product.', 'confirmation' => 'FW-OLD-1'])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->superAdmin)
            ->delete($url, ['password' => 'password', 'reason' => 'Unwanted test product.', 'confirmation' => 'wrong'])
            ->assertSessionHasErrors('product');

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();

        $this->actingAs($this->superAdmin)
            ->delete($url, ['password' => 'password', 'reason' => 'Unwanted test product.', 'confirmation' => 'FW-OLD-1'])
            ->assertSessionHasNoErrors();

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeFalse();
    });

    it('shows a Super Admin what a force delete would touch, and nobody else', function () {
        $product = forceDeleteTestProduct($this);
        $impactUrl = route('admin.catalog.products.trash.force-delete.impact', $product->public_id);

        $this->actingAs($this->superAdmin)->getJson($impactUrl)
            ->assertOk()
            ->assertJsonPath('impact.preserved.order_lines', 1)
            ->assertJsonPath('impact.deactivated.physical_units', 7)
            ->assertJsonPath('impact.blockers', []);

        $manager = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($manager)->getJson($impactUrl)->assertForbidden();
        $this->actingAs($manager)
            ->delete(route('admin.catalog.products.trash.force-delete', $product->public_id), [
                'password' => 'password', 'reason' => 'Unwanted test product.', 'confirmation' => 'FW-OLD-1',
            ])
            ->assertForbidden();
    });

    it('offers Force Delete on the Trash screen to a Super Admin only', function () {
        forceDeleteTestProduct($this);
        $manager = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($this->superAdmin)->get(route('admin.catalog.products.trash.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.force_delete', true));
        $this->actingAs($manager)->get(route('admin.catalog.products.trash.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.force_delete', false));
    });

    it('refuses anyone but a Super Admin, including a product manager who may delete', function () {
        $product = forceDeleteTestProduct($this);
        $manager = testPlatformStaff(PlatformRole::ProductManager);

        expect(fn () => app(ForceDeleteProduct::class)->handle($manager, $product, 'Unwanted test product.', 'FW-OLD-1'))
            ->toThrow(AuthorizationException::class);

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();
    });
});

describe('screens that read preserved history', function () {
    it('renders the stock screens for a trashed product and for a force-deleted one', function () {
        $product = forceDeleteTestProduct($this);
        $item = StockItem::query()->firstOrFail();

        // Trashed, still present: this used to fail with "property sku on null".
        $this->actingAs($this->superAdmin)->get(route('admin.inventory.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.sku', 'FW-OLD-1')
                ->where('items.data.0.product.name', 'Old rice cooker'));

        app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'FW-OLD-1');

        // Force deleted: everything is read from the snapshot.
        $this->actingAs($this->superAdmin)->get(route('admin.inventory.stock.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('items.data.0.sku', 'FW-OLD-1')
                ->where('items.data.0.product.name', 'Old rice cooker')
                ->where('items.data.0.product.deleted', true));

        $this->actingAs($this->superAdmin)->get(route('admin.inventory.stock.index', ['search' => 'rice']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 1));

        $this->actingAs($this->superAdmin)->get(route('admin.inventory.stock.show', $item->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('item.product.deleted', true)->has('movements.data', 2));
    });
});

describe('deleting categories once their products are gone', function () {
    it('purges a branch deepest first, but never while a product still sits in it', function () {
        $child = Category::create(['name' => 'Cookers', 'parent_id' => $this->category->id]);
        $product = forceDeleteTestProduct($this);

        $url = route('admin.catalog.categories.purge', $this->category->public_id);

        $this->actingAs($this->superAdmin)->delete($url, ['password' => 'password'])
            ->assertSessionHasErrors('category');
        expect(Category::query()->count())->toBe(2);

        app(ForceDeleteProduct::class)->handle($this->superAdmin, $product, 'Unwanted test product.', 'FW-OLD-1');

        $this->actingAs($this->superAdmin)->delete($url, ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($this->superAdmin)->delete($url, ['password' => 'password'])->assertSessionHasNoErrors();

        expect(Category::query()->count())->toBe(0)
            ->and($child->exists)->toBeTrue();
    });

    it('keeps the branch purge to Super Admins', function () {
        $manager = testPlatformStaff(PlatformRole::ProductManager);

        $this->actingAs($manager)
            ->delete(route('admin.catalog.categories.purge', $this->category->public_id), ['password' => 'password'])
            ->assertForbidden();

        expect(Category::query()->count())->toBe(1);
    });
});
