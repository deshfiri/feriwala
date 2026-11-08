<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Catalog\Enums\PackageScope;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\UserPackage;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Wholesale\Actions\ConfirmCheckout;
use App\Domain\Wholesale\Actions\OpenCart;
use App\Domain\Wholesale\Actions\SaveCheckoutAddress;
use App\Domain\Wholesale\Queries\PriceCheckout;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * The mobile wholesale orders (§14, §31.3): placed, paid, cancelled and followed
 * through the same Actions the web uses, only ever in the signed-in person's own
 * account, and never priced by anything the app sends.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->gatewayAnswer = new ArrayObject(['status' => 'VALID', 'currency_amount' => '20000.00', 'currency_type' => 'BDT']);

    Http::fake(function (ClientRequest $request) {
        if (str_contains($request->url(), 'gwprocess')) {
            return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']);
        }

        return Http::response($this->gatewayAnswer->getArrayCopy());
    });

    $this->package = Package::create([
        'slug' => 'mobile-order-'.Str::lower(Str::random(8)),
        'name' => 'Order package',
        'fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
        'is_active' => true,
        'is_public' => true,
    ]);

    $this->category = Category::create(['name' => 'Kitchen']);
    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    // Ten kettles at 2,000 taka: 20,000 taka.
    $this->kettle = Product::create([
        'name' => 'Electric kettle',
        'sku' => 'FW-KT',
        'category_id' => $this->category->id,
        'base_cost' => Money::fromDecimal('500.00', Currency::BDT),
        'wholesale_price' => Money::fromDecimal('2000.00', Currency::BDT),
        'min_order_quantity' => 1,
        'status' => ProductStatus::Active,
        'wholesale_status' => ProductStatus::WholesaleEnabled,
        'package_scope' => PackageScope::AllPackages,
    ]);

    $stock = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->kettle->id]);
    app(StockLedger::class)->move($stock, null, StockBucket::Available, 50, StockMovementType::Adjustment);

    $this->karim = mobileWholesaleAccount($this->package);
    $this->rahim = mobileWholesaleAccount($this->package);

    mobileWholesaleConfirmedCart($this->karim, 'sslcommerz');
});

function mobileWholesaleAccount(Package $package): BusinessAccount
{
    $account = testBusinessAccount(AccountStatus::Active);

    $subscription = UserPackage::create([
        'business_account_id' => $account->id,
        'package_id' => $package->id,
        'status' => UserPackageStatus::Active,
        'started_at' => now()->subDay(),
        'expires_at' => now()->addYear(),
        'paid_fee' => Money::fromDecimal('5000.00', Currency::BDT),
        'currency_code' => 'BDT',
    ]);

    $account->forceFill(['current_user_package_id' => $subscription->id])->save();

    return $account->refresh();
}

/**
 * Fill the account's cart through the mobile API, give it both addresses and confirm the checkout.
 */
function mobileWholesaleConfirmedCart(BusinessAccount $account, string $method): void
{
    Sanctum::actingAs($account->owner);

    test()->postJson(route('mobile.v1.wholesale.cart.items.store'), ['product' => test()->kettle->slug, 'quantity' => 10])
        ->assertSuccessful();

    $address = [
        'contact_name' => 'Karim Uddin',
        'contact_mobile' => '01712345678',
        'line_1' => 'House 12, Road 5',
        'area' => 'Mirpur',
        'city' => 'Dhaka',
        'postcode' => '1216',
    ];

    app(SaveCheckoutAddress::class)->handle($account->owner, AddressType::Billing, $address);
    app(SaveCheckoutAddress::class)->handle($account->owner, AddressType::Shipping, $address);

    app(ConfirmCheckout::class)->handle($account->owner, $account, $method, mobileWholesaleFingerprint($account));
}

function mobileWholesaleFingerprint(BusinessAccount $account): string
{
    return app(PriceCheckout::class)->quote(app(OpenCart::class)->find($account->owner, $account), $account)->fingerprint();
}

function mobileWholesalePlace(array $payload = [], ?BusinessAccount $account = null): TestResponse
{
    $account ??= test()->karim;
    Sanctum::actingAs($account->owner);

    return test()->postJson(route('mobile.v1.wholesale.orders.store'), ['fingerprint' => mobileWholesaleFingerprint($account), ...$payload]);
}

function mobileWholesalePlaced(): Order
{
    mobileWholesalePlace()->assertCreated();

    return Order::query()->where('business_account_id', test()->karim->id)->sole();
}

function mobileWholesaleWalletFunded(BusinessAccount $account, string $amount): Wallet
{
    $wallet = app(OpenWallet::class)->handle($account);

    app(WalletService::class)->credit(
        $wallet,
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal($amount, Currency::BDT),
        new PostingContext(source: 'test', description: 'Funding', idempotencyKey: 'fund:'.$wallet->id),
    );

    return $wallet->refresh();
}

/**
 * @return array<string, string>
 */
function mobileWholesaleReturnParams(string $reference): array
{
    $fields = ['tran_id' => $reference, 'val_id' => 'val-1', 'status' => 'VALID'];

    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed))),
    ];
}

describe('access', function () {
    test('every order endpoint needs a signed-in person', function () {
        // beforeEach confirmed a cart as the owner, which left that person signed in.
        app('auth')->forgetGuards();

        $this->getJson(route('mobile.v1.wholesale.orders.index'))->assertUnauthorized();
        $this->postJson(route('mobile.v1.wholesale.orders.store'), ['fingerprint' => str_repeat('a', 64)])->assertUnauthorized();
        $this->getJson(route('mobile.v1.wholesale.orders.show', 'x'))->assertUnauthorized();
        $this->postJson(route('mobile.v1.wholesale.orders.payment.return', 'x'))->assertUnauthorized();
    });

    test('an account that is not activated is turned away', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);
        Sanctum::actingAs($account->owner);

        $this->getJson(route('mobile.v1.wholesale.orders.index'))->assertForbidden();
        $this->postJson(route('mobile.v1.wholesale.orders.store'), ['fingerprint' => str_repeat('a', 64)])->assertForbidden();
    });
});

describe('placing an order', function () {
    test('the confirmed checkout becomes one order and the gateway address comes back', function () {
        mobileWholesalePlace(['customer_note' => 'Leave at the gate'])
            ->assertCreated()
            ->assertJsonPath('redirect_url', 'https://pay.test/go')
            ->assertJsonPath('data.status', 'payment_pending')
            ->assertJsonPath('data.total.amount', '20000.00')
            ->assertJsonPath('data.customer_note', 'Leave at the gate')
            ->assertJsonPath('can.pay', true)
            ->assertJsonPath('can.cancel', true);

        $order = Order::query()->where('business_account_id', $this->karim->id)->sole();

        expect($order->source->value)->toBe('erp_wholesale')
            ->and($order->placed_by)->toBe($this->karim->owner_id);
    });

    test('the total is the server price whatever amount the app sends', function () {
        mobileWholesalePlace(['total' => '1.00', 'amount' => '1', 'subtotal' => '1.00', 'discount' => '19999.00'])
            ->assertCreated()
            ->assertJsonPath('data.total.amount', '20000.00');

        $order = Order::query()->where('business_account_id', $this->karim->id)->sole();

        expect($order->total->toDecimal())->toBe('20000.00')
            ->and($order->payment->amount->toDecimal())->toBe('20000.00');
    });

    test('placing the same confirmation twice returns the first order', function () {
        $first = mobileWholesalePlace()->assertCreated()->json('data.id');

        mobileWholesalePlace()->assertOk()->assertJsonPath('data.id', $first);

        expect(Order::query()->where('business_account_id', $this->karim->id)->count())->toBe(1);
    });

    test('a summary that has changed is refused and nothing is placed', function () {
        mobileWholesalePlace(['fingerprint' => str_repeat('a', 64)])->assertUnprocessable();

        expect(Order::query()->count())->toBe(0);
    });

    test('a wallet that can no longer cover the order refuses it and places nothing', function () {
        $wallet = mobileWholesaleWalletFunded($this->karim, '20000.00');
        app(ConfirmCheckout::class)->handle($this->karim->owner, $this->karim, 'wallet', mobileWholesaleFingerprint($this->karim));

        // The balance is spent between confirming and placing.
        app(WalletService::class)->debit(
            $wallet,
            LedgerTransactionType::ServiceFeeDebit,
            Money::fromDecimal('15000.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'Spent elsewhere', idempotencyKey: 'elsewhere'),
        );

        mobileWholesalePlace()->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        expect(Order::query()->count())->toBe(0)
            ->and($wallet->refresh()->total->toDecimal())->toBe('5000.00');
    });

    test('an order is paid from the wallet once, however often payment is asked for', function () {
        $wallet = mobileWholesaleWalletFunded($this->karim, '30000.00');
        app(ConfirmCheckout::class)->handle($this->karim->owner, $this->karim, 'wallet', mobileWholesaleFingerprint($this->karim));

        mobileWholesalePlace()
            ->assertCreated()
            ->assertJsonPath('redirect_url', null)
            ->assertJsonPath('data.status', 'paid');

        $order = Order::query()->where('business_account_id', $this->karim->id)->sole();

        $this->postJson(route('mobile.v1.wholesale.orders.payment.store', $order->public_id));

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($wallet->refresh()->total->toDecimal())->toBe('10000.00')
            ->and(LedgerEntry::query()->where('payment_id', $order->payment->id)->count())->toBe(1);
    });
});

describe('reading orders', function () {
    test('the list holds only this account\'s orders, and the detail carries the lines', function () {
        $order = mobileWholesalePlaced();

        Sanctum::actingAs($this->rahim->owner);
        $this->getJson(route('mobile.v1.wholesale.orders.index'))->assertOk()->assertJsonPath('meta.total', 0);

        Sanctum::actingAs($this->karim->owner);
        $this->getJson(route('mobile.v1.wholesale.orders.index'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $order->public_id)
            ->assertJsonPath('data.0.total.amount', '20000.00');

        $this->getJson(route('mobile.v1.wholesale.orders.show', $order->public_id))
            ->assertOk()
            ->assertJsonPath('data.lines.0.sku', 'FW-KT')
            ->assertJsonPath('data.lines.0.quantity', 10)
            ->assertJsonPath('data.lines.0.unit_price.amount', '2000.00')
            ->assertJsonPath('can.pay', true);
    });

    test('another account\'s order is not found, by any endpoint', function () {
        $order = mobileWholesalePlaced();

        Sanctum::actingAs($this->rahim->owner);

        $this->getJson(route('mobile.v1.wholesale.orders.show', $order->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.wholesale.orders.cancellation.store', $order->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.wholesale.orders.payment.store', $order->public_id), ['payment_method' => 'wallet'])->assertNotFound();
        $this->postJson(route('mobile.v1.wholesale.orders.payment.return', $order->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.wholesale.orders.payment.failed', $order->public_id))->assertNotFound();
        $this->postJson(route('mobile.v1.wholesale.orders.payment.cancelled', $order->public_id))->assertNotFound();

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });
});

describe('cancelling and continuing to payment', function () {
    test('an unpaid order can be cancelled by the account', function () {
        $order = mobileWholesalePlaced();

        $this->postJson(route('mobile.v1.wholesale.orders.cancellation.store', $order->public_id))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('can.pay', false);

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    });

    test('a cancelled order cannot be paid for', function () {
        $order = mobileWholesalePlaced();
        $this->postJson(route('mobile.v1.wholesale.orders.cancellation.store', $order->public_id))->assertOk();

        $this->postJson(route('mobile.v1.wholesale.orders.payment.store', $order->public_id))->assertUnprocessable();
    });

    test('payment can be continued at the gateway', function () {
        $order = mobileWholesalePlaced();

        $this->postJson(route('mobile.v1.wholesale.orders.payment.store', $order->public_id))
            ->assertOk()
            ->assertJsonPath('redirect_url', 'https://pay.test/go');
    });

    test('switching to a wallet that cannot cover the order is refused', function () {
        $order = mobileWholesalePlaced();
        mobileWholesaleWalletFunded($this->karim, '100.00');

        $this->postJson(route('mobile.v1.wholesale.orders.payment.store', $order->public_id), ['payment_method' => 'wallet'])
            ->assertUnprocessable();

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending)
            ->and(LedgerEntry::query()->where('payment_id', $order->payment->id)->count())->toBe(0);
    });
});

describe('the gateway sending the person back', function () {
    test('a return the gateway confirms settles the order', function () {
        $order = mobileWholesalePlaced();
        $this->gatewayAnswer['tran_id'] = $order->payment->reference;

        $this->postJson(
            route('mobile.v1.wholesale.orders.payment.return', $order->public_id),
            mobileWholesaleReturnParams((string) $order->payment->reference),
        )
            ->assertOk()
            ->assertJsonPath('outcome', 'paid')
            ->assertJsonPath('data.status', 'paid');

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Paid);
    });

    test('a return that claims success without anything the gateway can confirm settles nothing', function () {
        $order = mobileWholesalePlaced();

        $this->postJson(route('mobile.v1.wholesale.orders.payment.return', $order->public_id), ['status' => 'VALID', 'amount' => '20000.00'])
            ->assertOk()
            ->assertJsonPath('outcome', 'checking');

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });

    test('a failed or cancelled report leaves the order waiting for payment', function (string $routeName) {
        $order = mobileWholesalePlaced();

        $this->postJson(route($routeName, $order->public_id))
            ->assertOk()
            ->assertJsonPath('outcome', 'not_completed')
            ->assertJsonPath('data.status', 'payment_pending');

        expect($order->refresh()->status)->toBe(OrderStatus::PaymentPending);
    })->with([
        'failed' => 'mobile.v1.wholesale.orders.payment.failed',
        'cancelled' => 'mobile.v1.wholesale.orders.payment.cancelled',
    ]);
});
