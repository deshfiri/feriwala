<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Actions\ManageCategories;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Actions\TransitionProduct;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Permanent deletion of a never-used Draft product, and of the empty categories
 * it leaves behind.
 *
 * Every product has status history from creation. That history must survive the
 * purge as a tombstone, and the append-only protection must keep refusing every
 * other change to it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = testPlatformStaff(PlatformRole::ProductManager);
    $this->category = Category::create(['name' => 'Kitchen']);
});

function purgeTestDraftWithHistory(object $test): Product
{
    $product = app(ManageProducts::class)->create($test->manager, [
        'name' => 'Rice cooker',
        'sku' => 'FW-PURGE-1',
        'category_id' => $test->category->public_id,
        'wholesale_price' => '2500.00',
        'base_cost' => '1800.00',
    ]);

    // Review and back: history on the lifecycle axis, product back in Draft.
    app(TransitionProduct::class)->handle($test->manager, $product, ProductStatus::PendingReview);
    app(TransitionProduct::class)->handle($test->manager, $product->refresh(), ProductStatus::Draft);

    app(ManageProducts::class)->trash($test->manager, $product->refresh(), 'Test product.');

    return $product->refresh();
}

describe('permanently deleting an unused draft', function () {
    it('removes the product and keeps its status history as a tombstone', function () {
        $product = purgeTestDraftWithHistory($this);
        $historyBefore = DB::table('product_status_history')->where('product_id', $product->id)->count();

        $this->actingAs($this->manager)
            ->delete(route('admin.catalog.products.trash.destroy', $product->public_id))
            ->assertSessionHasNoErrors();

        $rows = DB::table('product_status_history')->where('product_public_id', $product->public_id)->get();

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeFalse()
            ->and($historyBefore)->toBeGreaterThan(0)
            ->and($rows)->toHaveCount($historyBefore)
            ->and($rows->pluck('product_id')->filter()->all())->toBe([])
            ->and($rows->pluck('product_name')->unique()->all())->toBe(['Rice cooker'])
            ->and($rows->pluck('product_sku')->unique()->all())->toBe(['FW-PURGE-1']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalog.product_permanently_deleted']);
    });

    it('keeps tombstoned history append-only', function () {
        $product = purgeTestDraftWithHistory($this);
        app(ManageProducts::class)->permanentlyDelete($this->manager, $product);

        $other = Product::create(['name' => 'Other', 'sku' => 'FW-OTHER', 'category_id' => $this->category->id]);

        expect(fn () => DB::table('product_status_history')->where('product_public_id', $product->public_id)->update(['reason' => 'rewritten']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('product_status_history')->where('product_public_id', $product->public_id)->update(['product_id' => $other->id]))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('product_status_history')->where('product_public_id', $product->public_id)->update(['product_name' => 'Renamed']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('product_status_history')->where('product_public_id', $product->public_id)->delete())
            ->toThrow(QueryException::class);
    });

    it('refuses a forged snapshot while the product still exists', function () {
        $product = purgeTestDraftWithHistory($this);

        expect(fn () => DB::table('product_status_history')
            ->where('product_id', $product->id)
            ->update(['product_public_id' => '01FORGED000000000000000000']))
            ->toThrow(QueryException::class);
    });

    it('refuses a product that has left Draft', function () {
        $product = purgeTestDraftWithHistory($this);
        $product->forceFill(['status' => ProductStatus::Inactive])->save();

        expect(fn () => app(ManageProducts::class)->permanentlyDelete($this->manager, $product->refresh()))
            ->toThrow(CatalogRefused::class, 'still a draft');
        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();
    });

    it('refuses a draft that has stock recorded against it and leaves its history attached', function () {
        $product = purgeTestDraftWithHistory($this);
        $warehouse = Warehouse::create(['name' => 'Dhaka Depot', 'code' => 'DHK-'.random_int(100, 999), 'is_active' => true]);
        StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'product_variant_id' => null, 'available' => 1]);

        expect(fn () => app(ManageProducts::class)->permanentlyDelete($this->manager, $product))
            ->toThrow(CatalogRefused::class, 'warehouse stock');
        expect(DB::table('product_status_history')->where('product_id', $product->id)->exists())->toBeTrue();
    });

    it('refuses staff without the delete permission', function () {
        $product = purgeTestDraftWithHistory($this);
        $viewer = testPlatformStaff(PlatformRole::InventoryManager);

        $this->actingAs($viewer)
            ->delete(route('admin.catalog.products.trash.destroy', $product->public_id))
            ->assertForbidden();

        expect(Product::withTrashed()->whereKey($product->id)->exists())->toBeTrue();
    });
});

describe('deleting categories after the purge', function () {
    it('deletes empty categories child-first and refuses a non-empty parent', function () {
        $child = Category::create(['name' => 'Cookers', 'parent_id' => $this->category->id]);

        expect(fn () => app(ManageCategories::class)->delete($this->manager, $this->category))
            ->toThrow(CatalogRefused::class, 'subcategor');

        app(ManageCategories::class)->delete($this->manager, $child);
        app(ManageCategories::class)->delete($this->manager, $this->category->refresh());

        expect(Category::query()->count())->toBe(0);
    });

    it('refuses a category while a trashed product still sits in it, then allows it after the purge', function () {
        $product = purgeTestDraftWithHistory($this);

        expect(fn () => app(ManageCategories::class)->delete($this->manager, $this->category))
            ->toThrow(CatalogRefused::class, 'product');

        app(ManageProducts::class)->permanentlyDelete($this->manager, $product);
        app(ManageCategories::class)->delete($this->manager, $this->category->refresh());

        expect(Category::query()->count())->toBe(0);
    });
});
