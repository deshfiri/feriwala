<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Wholesale\Actions\SetCartLine;
use App\Domain\Wholesale\Models\Cart;
use App\Domain\Wholesale\Models\CartItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The wholesale cart under parallel requests, with real processes (P4-4, §43).
 *
 * A double click, a retried request, two tabs: several requests setting the same
 * line at the same instant, each on its own connection. However they fall there
 * is one cart for the person and one line for the product — the unique indexes
 * settle a first-cart race and the cart's row lock serialises the line — and the
 * quantity is one that was actually asked for, never a sum.
 *
 * As in the stock races (P3-31), the data is committed so the workers can see
 * it, and everything the test wrote is removed afterwards.
 */

beforeEach(function () {
    config()->set('database.connections.cart_race', config('database.connections.pgsql'));
    config()->set('database.default', 'cart_race');

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->package = Package::create([
        'slug' => 'cart-race-'.Str::lower(Str::random(8)),
        'name' => 'Cart race package',
        'fee_minor' => 500000,
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);
    $subscription = UserPackage::create([
        'business_account_id' => $this->account->id,
        'package_id' => $this->package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'paid_fee_minor' => 500000,
        'currency_code' => 'BDT',
    ]);
    $this->account->forceFill(['current_user_package_id' => $subscription->id])->save();

    $this->category = Category::create(['name' => 'Cart race '.uniqid()]);
    $this->product = Product::create([
        'name' => 'Cart race kettle',
        'sku' => 'FW-CRACE-'.strtoupper(uniqid()),
        'category_id' => $this->category->id,
        'wholesale_price_minor' => 200000,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);
    $this->warehouse = Warehouse::create(['code' => 'CRACE-'.strtoupper(uniqid()), 'name' => 'Cart race warehouse', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);

    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 50, StockMovementType::Adjustment);
});

afterEach(function () {
    DB::statement('ALTER TABLE stock_movements DISABLE TRIGGER stock_movements_no_delete');

    DB::table('cart_items')->where('product_id', $this->product->id)->delete();
    DB::table('carts')->where('business_account_id', $this->account->id)->delete();
    DB::table('stock_movements')->where('stock_item_id', $this->item->id)->delete();
    DB::table('stock_items')->where('id', $this->item->id)->delete();
    DB::table('warehouses')->where('id', $this->warehouse->id)->delete();
    // The catalogue refuses to delete a live product; a draft is the only kind
    // that may go, so the test's own product is returned to one first.
    DB::table('products')->where('id', $this->product->id)->update(['status' => ProductStatus::Draft->value]);
    DB::table('products')->where('id', $this->product->id)->delete();
    DB::table('categories')->where('id', $this->category->id)->delete();
    DB::table('business_accounts')->where('id', $this->account->id)->update(['current_user_package_id' => null]);
    DB::table('user_packages')->where('business_account_id', $this->account->id)->delete();
    DB::table('packages')->where('id', $this->package->id)->delete();
    DB::table('business_accounts')->where('id', $this->account->id)->delete();
    DB::table('users')->where('id', $this->account->owner_id)->delete();

    DB::statement('ALTER TABLE stock_movements ENABLE TRIGGER stock_movements_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function cartRace(int $count, Closure $work): void
{
    DB::purge('cart_race');

    $startAt = microtime(true) + 0.4;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // What happened is read from the database afterwards.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function cartRaceSet(int $quantity): void
{
    /** @var BusinessAccount $account */
    $account = BusinessAccount::query()->findOrFail(test()->account->id);
    /** @var User $owner */
    $owner = User::query()->findOrFail($account->owner_id);

    app(SetCartLine::class)->handle($owner, $account, Product::query()->findOrFail(test()->product->id), null, $quantity);
}

it('ends with one cart and one line however many workers set the same line at once', function () {
    cartRace(6, fn () => cartRaceSet(10));

    expect(Cart::query()->where('user_id', $this->account->owner_id)->count())->toBe(1)
        ->and(CartItem::query()->where('product_id', $this->product->id)->count())->toBe(1)
        ->and(CartItem::query()->where('product_id', $this->product->id)->sole()->quantity)->toBe(10);
});

it('keeps a quantity somebody actually asked for, never a sum, when different quantities race', function () {
    cartRace(6, fn (int $worker) => cartRaceSet(5 + $worker));

    $line = CartItem::query()->where('product_id', $this->product->id)->sole();

    expect(Cart::query()->where('user_id', $this->account->owner_id)->count())->toBe(1)
        ->and($line->quantity)->toBeGreaterThanOrEqual(5)
        ->and($line->quantity)->toBeLessThanOrEqual(10);
});
