<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\PlaceWholesaleOrder;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Actions\SetCartLine;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Placing wholesale orders under parallel requests, with real processes
 * (P4-9, P4-10, §19.1, §43).
 *
 * Several accounts ordering the last of a product at the same instant, and one
 * person submitting the same confirmation several times at once, each on its own
 * connection. However they fall: stock is never oversold, a refused order leaves
 * no payment, order or reservation behind, and one confirmation is one order with
 * one payment and one reservation.
 *
 * As in the other races, the data is committed so the workers can see it, and
 * everything the test wrote is removed afterwards.
 */

const WHOLESALE_ORDER_RACE_SETTINGS = [
    'payment.sslcommerz.mode' => 'sandbox',
    'payment.sslcommerz.sandbox.store_id' => 'store',
    'payment.sslcommerz.sandbox.store_password' => 'pass',
];

beforeEach(function () {
    config()->set('database.connections.order_race', config('database.connections.pgsql'));
    config()->set('database.default', 'order_race');

    $settings = app(SettingsRepository::class);
    $this->createdSettings = [];

    foreach (WHOLESALE_ORDER_RACE_SETTINGS as $key => $value) {
        if (! Setting::query()->where('key', $key)->exists()) {
            $settings->define($key, 'payment', SettingType::String, $value, isEncrypted: $key !== 'payment.sslcommerz.mode');
            $this->createdSettings[] = $key;
        }
    }

    $this->package = Package::create([
        'slug' => 'order-race-'.Str::lower(Str::random(8)),
        'name' => 'Order race package',
        'currency_code' => 'BDT',
        'fee' => Money::fromDecimal('5000.00'),
        'is_active' => true,
        'is_public' => true,
    ]);

    $this->category = Category::create(['name' => 'Order race '.uniqid()]);
    $this->product = Product::create([
        'name' => 'Order race kettle',
        'sku' => 'FW-ORACE-'.strtoupper(uniqid()),
        'category_id' => $this->category->id,
        'wholesale_price' => Money::fromDecimal('2000.00'),
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);
    $this->warehouse = Warehouse::create(['code' => 'ORACE-'.strtoupper(uniqid()), 'name' => 'Order race warehouse', 'is_default' => true]);
    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);

    // An object, so the buyer helper can add to it through test().
    $this->accounts = new ArrayObject;
});

afterEach(function () {
    $accountIds = array_map(fn (BusinessAccount $account) => $account->id, $this->accounts->getArrayCopy());
    $ownerIds = array_map(fn (BusinessAccount $account) => $account->owner_id, $this->accounts->getArrayCopy());
    $orderIds = DB::table('orders')->whereIn('business_account_id', $accountIds)->pluck('id');
    $paymentIds = DB::table('payments')->whereIn('business_account_id', $accountIds)->pluck('id');

    foreach (['order_status_history', 'order_items', 'orders', 'stock_movements', 'products'] as $table) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
    }

    try {
        DB::table('order_status_history')->whereIn('order_id', $orderIds)->delete();
        DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('orders')->whereIn('id', $orderIds)->delete();
        DB::table('payment_tax_lines')->whereIn('payment_id', $paymentIds)->delete();
        DB::table('payment_allocations')->whereIn('payment_id', $paymentIds)->delete();
        DB::table('payments')->whereIn('id', $paymentIds)->delete();
        DB::table('stock_reservations')->where('stock_item_id', $this->item->id)->delete();
        DB::table('stock_movements')->where('stock_item_id', $this->item->id)->delete();
        DB::table('stock_items')->where('id', $this->item->id)->delete();
        DB::table('warehouses')->where('id', $this->warehouse->id)->delete();
        DB::table('cart_items')->where('product_id', $this->product->id)->delete();
        DB::table('carts')->whereIn('business_account_id', $accountIds)->delete();
        DB::table('user_addresses')->whereIn('user_id', $ownerIds)->delete();
        DB::table('products')->where('id', $this->product->id)->delete();
        DB::table('categories')->where('id', $this->category->id)->delete();
        DB::table('business_accounts')->whereIn('id', $accountIds)->update(['current_user_package_id' => null]);
        DB::table('user_packages')->whereIn('business_account_id', $accountIds)->delete();
        DB::table('packages')->where('id', $this->package->id)->delete();
        DB::table('business_accounts')->whereIn('id', $accountIds)->delete();
        DB::table('users')->whereIn('id', $ownerIds)->delete();
        DB::table('settings')->whereIn('key', $this->createdSettings)->delete();
    } finally {
        foreach (['order_status_history', 'order_items', 'orders', 'stock_movements', 'products'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
        }
    }
});

/**
 * An account with wholesale, a confirmed checkout for `$quantity` kettles, and
 * the fingerprint of that checkout.
 *
 * @return array{0: BusinessAccount, 1: string}
 */
function wholesaleOrderRaceBuyer(int $quantity): array
{
    $account = testBusinessAccount(AccountStatus::Active);
    test()->accounts[] = $account;

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => test()->package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'currency_code' => 'BDT',
        'paid_fee' => Money::fromDecimal('5000.00'),
    ]);
    $account->forceFill(['current_user_package_id' => $subscription->id])->save();
    $account->refresh();

    app(SetCartLine::class)->handle($account->owner, $account, test()->product, null, $quantity);

    $address = ['contact_name' => 'Buyer', 'contact_mobile' => '01712345678', 'line_1' => 'Road 5', 'city' => 'Dhaka'];
    app(SaveCheckoutAddress::class)->handle($account->owner, AddressType::Billing, $address);
    app(SaveCheckoutAddress::class)->handle($account->owner, AddressType::Shipping, $address);

    $fingerprint = app(PriceCheckout::class)->quote(app(OpenCart::class)->find($account->owner, $account), $account)->fingerprint();
    app(ConfirmCheckout::class)->handle($account->owner, $account, 'sslcommerz', $fingerprint);

    return [$account, $fingerprint];
}

/**
 * Run one piece of work in several real processes, started together.
 *
 * @param  array<int, mixed>  $jobs  one worker per entry
 */
function wholesaleOrderRace(array $jobs, Closure $work): void
{
    DB::purge('order_race');

    $startAt = microtime(true) + 0.5;
    $pids = [];

    foreach ($jobs as $job) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($job);
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

/**
 * @param  array{0: BusinessAccount, 1: string}  $buyer
 */
function wholesaleOrderRacePlace(array $buyer): void
{
    [$account, $fingerprint] = $buyer;

    /** @var BusinessAccount $fresh */
    $fresh = BusinessAccount::query()->findOrFail($account->id);
    /** @var User $owner */
    $owner = User::query()->findOrFail($fresh->owner_id);

    app(PlaceWholesaleOrder::class)->handle($owner, $fresh, $fingerprint);
}

it('never oversells when several accounts order the last of the stock at once', function () {
    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 25, StockMovementType::Adjustment);

    // Four accounts want ten each; there are twenty-five.
    $buyers = [wholesaleOrderRaceBuyer(10), wholesaleOrderRaceBuyer(10), wholesaleOrderRaceBuyer(10), wholesaleOrderRaceBuyer(10)];

    wholesaleOrderRace($buyers, fn (array $buyer) => wholesaleOrderRacePlace($buyer));

    $accountIds = array_map(fn (BusinessAccount $account) => $account->id, $this->accounts->getArrayCopy());
    $orders = Order::query()->whereIn('business_account_id', $accountIds)->with(['items.stockReservation', 'payment'])->get();
    $item = StockItem::query()->findOrFail($this->item->id);

    expect($orders)->toHaveCount(2)
        ->and($item->reserved)->toBe(20)
        ->and($item->available)->toBe(5)
        // A refused order leaves nothing behind: no payment, no reservation.
        ->and(DB::table('payments')->whereIn('business_account_id', $accountIds)->count())->toBe(2)
        ->and(StockReservation::query()->where('stock_item_id', $this->item->id)->count())->toBe(2);

    foreach ($orders as $order) {
        expect($order->status)->toBe(OrderStatus::PaymentPending)
            ->and($order->payment?->payable_id)->toBe($order->id)
            ->and($order->items)->toHaveCount(1)
            ->and($order->items->first()?->stockReservation?->status)->toBe(StockReservationStatus::Active)
            ->and($order->items->first()?->stockReservation?->business_account_id)->toBe($order->business_account_id);
    }
});

it('places one order, one payment and one reservation when one confirmation is submitted several times at once', function () {
    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 50, StockMovementType::Adjustment);

    $buyer = wholesaleOrderRaceBuyer(10);

    wholesaleOrderRace(array_fill(0, 5, $buyer), fn (array $buyer) => wholesaleOrderRacePlace($buyer));

    $account = $this->accounts[0];

    expect(Order::query()->where('business_account_id', $account->id)->count())->toBe(1)
        ->and(DB::table('payments')->where('business_account_id', $account->id)->count())->toBe(1)
        ->and(StockReservation::query()->where('stock_item_id', $this->item->id)->count())->toBe(1)
        ->and(StockItem::query()->findOrFail($this->item->id)->reserved)->toBe(10);
});
