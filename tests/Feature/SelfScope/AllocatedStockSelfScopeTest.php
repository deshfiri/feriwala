<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockAllocation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Inventory\StockLedger;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * An account's own allocated stock, and nobody else's (P3-30, §19, §31.3).
 *
 * The screen is reached through the membership, so there is no account
 * identifier to change; what it shows is per SKU, summed across active
 * warehouses, with no warehouse detail — and another account, a modified
 * parameter or a member of platform staff sees none of it.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $category = Category::create(['name' => 'Apparel']);
    $shirt = Product::create(['name' => 'Polo Shirt', 'sku' => 'FW-PL', 'category_id' => $category->id]);
    $medium = ProductVariant::create(['product_id' => $shirt->id, 'sku' => 'FW-PL-M', 'combination_key' => 'm']);

    $this->dhaka = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->chattogram = Warehouse::create(['code' => 'CTG', 'name' => 'Chattogram', 'priority' => 10]);

    $this->karim = testBusinessAccount(AccountStatus::Active);
    $this->rahim = testBusinessAccount(AccountStatus::Active);

    $this->items = [];

    foreach ([$this->dhaka, $this->chattogram] as $warehouse) {
        $item = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $shirt->id, 'product_variant_id' => $medium->id]);
        app(StockLedger::class)->move($item, null, StockBucket::Available, 10, StockMovementType::Adjustment);
        $this->items[$warehouse->code] = $item;
    }

    app(StockAllocations::class)->allocate($this->items['DHK'], $this->karim, 2);
    app(StockAllocations::class)->allocate($this->items['CTG'], $this->karim, 1);
});

it('shows an account its own allocated stock per SKU, summed across warehouses, with no warehouse detail', function () {
    $this->actingAs($this->karim->owner)
        ->get(route('allocated-stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('allocated-stock/index')
            ->has('allocations', 1)
            ->has('allocations.0', 4)
            ->missing('allocations.0.warehouse')
            ->where('allocations.0.sku', 'FW-PL-M')
            ->where('allocations.0.product', 'Polo Shirt')
            ->where('allocations.0.quantity', 3)
            ->where('total', 3)
            ->where('can_trade', true)
            ->where('account.holdsAllocatedStock', true));
});

it('shows another account nothing of it, whatever the address says', function () {
    $this->actingAs($this->rahim->owner)
        ->get(route('allocated-stock.index', ['account' => $this->karim->public_id, 'business_account_id' => $this->karim->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('allocations', 0)
            ->where('total', 0)
            ->where('account.holdsAllocatedStock', false));
});

it('lets a member of the account\'s staff read it too', function () {
    $member = User::factory()->staff()->staffOf($this->karim, AccountRole::Staff)->create();

    $this->actingAs($member)
        ->get(route('allocated-stock.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('allocations.0.quantity', 3));
});

it('is not a screen for platform staff, who have no business', function () {
    // D23: the business gate sends them back to their own side, as it does from
    // every business screen, and no allocation is ever rendered for them.
    $response = $this->actingAs(testPlatformStaff(PlatformRole::InventoryManager))
        ->get(route('allocated-stock.index'))
        ->assertRedirect();

    expect($response->headers->get('Location'))->not->toContain('allocated-stock');
});

it('leaves out stock in a switched-off warehouse and allocations already emptied', function () {
    $this->chattogram->forceFill(['is_active' => false])->save();

    $dhaka = StockAllocation::query()->where('stock_item_id', $this->items['DHK']->id)->sole();
    app(StockAllocations::class)->release($dhaka, 2);

    $this->actingAs($this->karim->owner)
        ->get(route('allocated-stock.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('allocations', 0)
            ->where('account.holdsAllocatedStock', false));
});
