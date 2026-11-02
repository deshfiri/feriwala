<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Actions\SynchronizeOnlineCollectionWithPayment;
use App\Domain\Order\Actions\SynchronizeOrderProceedsWithDelivery;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * Real-process races for an online-paid Store Order's profit (D36): the same
 * shape as StoreOrderProfitConcurrencyTest, workers forked to race for the same
 * rows on a connection whose writes actually commit, then asked afterwards what
 * happened.
 *
 * Covered: the payment's collection fact and delivery arriving together from
 * several workers, for a Platform-gateway order (the profit is credited exactly
 * once) and for a Partner-gateway one (nothing is ever credited).
 */
beforeEach(function () {
    config()->set('database.connections.online_profit_race', config('database.connections.pgsql'));
    config()->set('database.default', 'online_profit_race');

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->website = Website::factory()->active()->forAccount($this->account)->create();
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

afterEach(function () {
    // What the race committed outlives the test. The ledger and the settlements
    // refuse deletion by design, and this is the one place allowed to reach past.
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_delete');
    DB::statement('ALTER TABLE wallet_transaction_events DISABLE TRIGGER wallet_transaction_events_no_delete');
    DB::statement('ALTER TABLE order_proceeds_settlements DISABLE TRIGGER order_proceeds_settlements_kept');

    DB::table('order_proceeds_settlements')->where('business_account_id', $this->account->id)->delete();
    DB::table('wallet_transaction_events')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();

    DB::statement('ALTER TABLE order_proceeds_settlements ENABLE TRIGGER order_proceeds_settlements_kept');
    DB::statement('ALTER TABLE wallet_transaction_events ENABLE TRIGGER wallet_transaction_events_no_delete');
    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function onlineProfitRace(int $count, Closure $work): void
{
    DB::purge('online_profit_race');

    $startAt = microtime(true) + 0.3;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // Losing the race is an expected outcome for one side of these.
                // What happened is read back from the database afterwards.
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
 * A settled online Store Order for ৳5,200, of which the platform keeps ৳5,000:
 * a ৳200 profit. `$partnerOwned` makes the payment the Partner's own.
 */
function onlineProfitRaceOrder(bool $partnerOwned = false): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $account = test()->account;
    $website = test()->website;
    $ownership = [];

    if ($partnerOwned) {
        $configuration = PartnerGatewayConfiguration::create([
            'business_account_id' => $account->id, 'website_id' => $website->id, 'provider' => 'sslcommerz', 'status' => 'active',
        ]);
        $version = PartnerGatewayConfigurationVersion::create([
            'partner_gateway_configuration_id' => $configuration->id,
            'business_account_id' => $account->id,
            'website_id' => $website->id,
            'version' => 1,
            'provider' => 'sslcommerz',
            'mode' => 'sandbox',
            'credentials' => ['store_id' => 'race-store', 'store_password' => 'race-pass'],
        ]);

        $ownership = [
            'gateway_ownership' => GatewayOwnership::Partner,
            'funds_destination' => 'partner',
            'website_id' => $website->id,
            'partner_gateway_configuration_version_id' => $version->id,
        ];
    } else {
        $ownership = [
            'gateway_ownership' => GatewayOwnership::Platform,
            'funds_destination' => 'platform',
            'website_id' => $website->id,
        ];
    }

    $payment = Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::WebsiteOrder,
        'status' => PaymentStatus::Paid,
        'currency_code' => 'BDT',
        'amount' => $money('5200.00'),
        'gateway' => 'sslcommerz',
        'gateway_reference' => 'race-'.bin2hex(random_bytes(4)),
        'completed_at' => now(),
        ...$ownership,
    ]);

    $customer = WebsiteCustomer::create([
        'website_id' => $website->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Race customer',
    ]);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    $order = Order::create([
        'source' => OrderSource::Website,
        'status' => OrderStatus::Paid->value,
        'business_account_id' => $account->id,
        'website_id' => $website->id,
        'website_customer_id' => $customer->id,
        'storefront_order_reference' => 'SF-RACE-'.strtoupper(bin2hex(random_bytes(3))),
        'idempotency_key' => bin2hex(random_bytes(16)),
        'billing_address' => $address,
        'shipping_address' => $address,
        'payment_id' => $payment->id,
        'account_type' => AccountType::NonConditional,
        'customer' => ['contact_name' => 'Race fixture', 'mobile' => '01700000000'],
        'currency_code' => 'BDT',
        'subtotal' => $money('5200.00'),
        'discount' => $money('0.00'),
        'delivery' => $money('0.00'),
        'tax' => $money('0.00'),
        'tax_included' => $money('0.00'),
        'total' => $money('5200.00'),
        'placed_at' => now(),
    ]);

    $order->items()->create([
        'line_number' => 1,
        'sku' => 'ONLINE-RACE-'.strtoupper(bin2hex(random_bytes(3))),
        'product_name' => 'Race fixture product',
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => $money('2600.00'),
        'line_subtotal' => $money('5200.00'),
        'discount' => $money('0.00'),
        'tax' => $money('0.00'),
        'tax_included' => $money('0.00'),
        'line_total' => $money('5200.00'),
        'platform_retained_amount' => $money('5000.00'),
    ]);

    return $order;
}

/**
 * What one worker does: the payment's collection fact and the delivery, in an
 * order that alternates by worker so both arrive first in some of them.
 */
function onlineProfitRaceWork(int $orderId, int $worker): void
{
    /** @var Order $order */
    $order = Order::query()->findOrFail($orderId)->load('items', 'payment');
    $collect = app(SynchronizeOnlineCollectionWithPayment::class);
    $deliver = app(SynchronizeOrderProceedsWithDelivery::class);

    if ($worker % 2 === 0) {
        $collect->handle($order, $order->payment);
        $deliver->handle($order, OrderDeliveryStatus::Delivered);

        return;
    }

    $deliver->handle($order, OrderDeliveryStatus::Delivered);
    $collect->handle($order, $order->payment);
}

it('credits an online order\'s profit exactly once when several workers collect and deliver it', function () {
    $order = onlineProfitRaceOrder();

    onlineProfitRace(6, fn (int $worker) => onlineProfitRaceWork($order->id, $worker));

    $wallet = Wallet::query()->findOrFail($this->wallet->id);
    $settlement = OrderProceedsSettlement::query()->where('order_id', $order->id)->sole();

    expect($settlement->collection_channel)->toBe(CollectionChannel::PlatformGateway)
        ->and($settlement->eligible_at)->not->toBeNull()
        ->and($settlement->reseller_earning?->toDecimal())->toBe('200.00')
        ->and($wallet->total->toDecimal())->toBe('200.00')
        ->and(LedgerEntry::query()->where('wallet_id', $wallet->id)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('wallet_id', $wallet->id)->sole()->balance_type)->toBe(BalanceType::Withdrawable)
        ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
});

it('never credits a Partner-gateway order\'s profit, however many workers settle it', function () {
    $order = onlineProfitRaceOrder(partnerOwned: true);

    onlineProfitRace(6, fn (int $worker) => onlineProfitRaceWork($order->id, $worker));

    $wallet = Wallet::query()->findOrFail($this->wallet->id);
    $settlement = OrderProceedsSettlement::query()->where('order_id', $order->id)->sole();

    expect($settlement->collection_channel)->toBe(CollectionChannel::PartnerGatewayExternal)
        ->and($settlement->eligible_at)->not->toBeNull()
        ->and($settlement->reseller_earning?->toDecimal())->toBe('0.00')
        ->and($settlement->wallet_transaction_id)->toBeNull()
        ->and($wallet->total->toDecimal())->toBe('0.00')
        ->and(LedgerEntry::query()->where('wallet_id', $wallet->id)->count())->toBe(0);
});
