<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Actions\ReconcileStorefrontHistory;
use App\Domain\Order\Data\StorefrontReconciliationOptions;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Two reconciliation processes at once, for real (D40): forked workers on a
 * connection whose writes commit, both running `--apply` over the same old,
 * delivered, paid Storefront order. One settlement, one credit.
 */
beforeEach(function () {
    config()->set('database.connections.reconcile_race', config('database.connections.pgsql'));
    config()->set('database.default', 'reconcile_race');
    Storage::fake('local');

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->website = Website::factory()->active()->forAccount($this->account)->create();
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

afterEach(function () {
    // What the race committed outlives the test. These tables refuse deletion
    // by design, and this is the one place allowed to reach past that.
    $profits = DB::table('partner_storefront_profits')->where('business_account_id', $this->account->id)->pluck('id');

    foreach ([
        'order_proceeds_settlements' => 'order_proceeds_settlements_kept',
        'ledger_entries' => 'ledger_entries_no_delete',
        'wallet_transaction_events' => 'wallet_transaction_events_no_delete',
        'partner_storefront_profit_lines' => 'partner_storefront_profit_lines_no_delete',
        'partner_storefront_profits' => 'partner_storefront_profits_no_delete',
    ] as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    }

    DB::table('storefront_reconciliation_items')->whereIn('order_id', DB::table('orders')->where('business_account_id', $this->account->id)->select('id'))->delete();
    DB::table('storefront_reconciliation_runs')->delete();
    DB::table('partner_storefront_profit_lines')->whereIn('partner_storefront_profit_id', $profits)->delete();
    DB::table('partner_storefront_profits')->whereIn('id', $profits)->delete();
    DB::table('order_proceeds_settlements')->where('business_account_id', $this->account->id)->delete();
    DB::table('wallet_transaction_events')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();

    foreach ([
        'order_proceeds_settlements' => 'order_proceeds_settlements_kept',
        'ledger_entries' => 'ledger_entries_no_delete',
        'wallet_transaction_events' => 'wallet_transaction_events_no_delete',
        'partner_storefront_profit_lines' => 'partner_storefront_profit_lines_no_delete',
        'partner_storefront_profits' => 'partner_storefront_profits_no_delete',
    ] as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }
});

function reconcileRace(int $count, Closure $work): void
{
    DB::purge('reconcile_race');
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
                // Read back from the database afterwards.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('creates one settlement and one credit when two processes reconcile the same order', function () {
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $payment = Payment::create([
        'business_account_id' => $this->account->id, 'purpose' => PaymentPurpose::WebsiteOrder, 'status' => PaymentStatus::Paid,
        'currency_code' => 'BDT', 'amount' => $money('5200.00'), 'gateway' => 'sslcommerz', 'gateway_reference' => 'rr-'.bin2hex(random_bytes(4)),
        'completed_at' => now()->subDays(4), 'gateway_ownership' => GatewayOwnership::Platform, 'funds_destination' => 'platform', 'website_id' => $this->website->id,
    ]);
    $customer = WebsiteCustomer::create(['website_id' => $this->website->id, 'mobile' => '+88017'.random_int(10000000, 99999999), 'name' => 'Race customer']);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    $order = Order::create([
        'source' => OrderSource::Website, 'status' => OrderStatus::Paid->value, 'business_account_id' => $this->account->id,
        'website_id' => $this->website->id, 'website_customer_id' => $customer->id, 'storefront_order_reference' => 'SF-RR-'.strtoupper(bin2hex(random_bytes(3))),
        'idempotency_key' => bin2hex(random_bytes(16)), 'billing_address' => $address, 'shipping_address' => $address, 'payment_id' => $payment->id,
        'account_type' => AccountType::NonConditional, 'customer' => ['contact_name' => 'Race', 'mobile' => '01700000000'], 'currency_code' => 'BDT',
        'subtotal' => $money('5200.00'), 'discount' => $money('0.00'), 'delivery' => $money('0.00'), 'tax' => $money('0.00'), 'tax_included' => $money('0.00'),
        'total' => $money('5200.00'), 'placed_at' => now()->subDays(5),
    ]);
    $order->items()->create([
        'line_number' => 1, 'sku' => 'RR-'.strtoupper(bin2hex(random_bytes(3))), 'product_name' => 'Race product', 'quantity' => 2, 'currency_code' => 'BDT',
        'unit_price' => $money('2600.00'), 'line_subtotal' => $money('5200.00'), 'discount' => $money('0.00'), 'tax' => $money('0.00'),
        'tax_included' => $money('0.00'), 'line_total' => $money('5200.00'), 'platform_retained_amount' => $money('5000.00'),
    ]);
    $at = CarbonImmutable::now()->subDays(3);
    foreach ([OrderDeliveryStatus::CourierAssigned, OrderDeliveryStatus::Shipped, OrderDeliveryStatus::OutForDelivery, OrderDeliveryStatus::Delivered] as $step) {
        $order->refresh()->moveDeliveryTo($step, new StatusChange(reason: 'history', at: $at), OrderStatusChangeSource::Staff);
    }

    reconcileRace(2, fn () => app(ReconcileStorefrontHistory::class)->run(new StorefrontReconciliationOptions(apply: true, order: $order->reference, courierApi: false)));

    expect(PartnerStorefrontProfit::query()->where('order_id', $order->id)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('idempotency_key', 'partner-storefront-profit:'.$order->public_id)->count())->toBe(1)
        ->and(Wallet::query()->findOrFail($this->wallet->id)->total->toDecimal())->toBe('200.00')
        ->and(app(VerifyLedgerIntegrity::class)->problemsFor(Wallet::query()->findOrFail($this->wallet->id)))->toBe([]);
});
