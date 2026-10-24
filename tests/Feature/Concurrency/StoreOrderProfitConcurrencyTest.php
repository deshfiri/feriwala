<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Order\Actions\EvaluateOrderProceedsEligibility;
use App\Domain\Order\Actions\ReverseOrderProceedsSettlement;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * Real-process races for the money a Non-Conditional store earns, and for typed
 * balances (D-new): the same shape as {@see WalletConcurrencyTest}, workers forked
 * to race for the same rows on a connection whose writes actually commit, then
 * asked afterwards what happened.
 *
 * Covered here: duplicate settlement of one order line's profit, a return
 * reversing that profit while it is being settled, and a withdrawal racing a
 * platform spend for the same money.
 */
beforeEach(function () {
    config()->set('database.connections.store_profit_race', config('database.connections.pgsql'));
    config()->set('database.default', 'store_profit_race');

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

afterEach(function () {
    // What the race committed outlives the test. The ledger refuses deletion by
    // design, and this is the one place allowed to reach past it.
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_delete');
    DB::statement('ALTER TABLE wallet_transaction_events DISABLE TRIGGER wallet_transaction_events_no_delete');

    DB::table('order_proceeds_settlements')->where('business_account_id', $this->account->id)->update(['wallet_transaction_id' => null]);
    DB::table('wallet_transaction_events')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();

    DB::statement('ALTER TABLE wallet_transaction_events ENABLE TRIGGER wallet_transaction_events_no_delete');
    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function storeProfitRace(int $count, Closure $work): void
{
    DB::purge('store_profit_race');

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

function storeProfitRaceWallet(): Wallet
{
    /** @var Wallet $wallet */
    $wallet = Wallet::query()->findOrFail(test()->wallet->id);

    return $wallet;
}

/**
 * One order line sold by the Non-Conditional account for ৳6,000, of which the
 * platform keeps ৳5,000 (the suggested price times two): a ৳1,000 profit.
 */
function storeProfitRaceLine(): OrderItem
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);

    $order = Order::create([
        'source' => OrderSource::ManualEntry,
        'status' => OrderStatus::Confirmed->value,
        'business_account_id' => test()->account->id,
        'account_type' => AccountType::NonConditional,
        'customer' => ['contact_name' => 'Race fixture', 'email' => 'race@test.example', 'mobile' => '01700000000'],
        'currency_code' => 'BDT',
        'subtotal' => $money('6000.00'),
        'total' => $money('6000.00'),
        'placed_at' => now(),
    ]);

    return $order->items()->create([
        'line_number' => 1,
        'sku' => 'STORE-RACE-'.strtoupper(bin2hex(random_bytes(3))),
        'product_name' => 'Race fixture product',
        'quantity' => 2,
        'currency_code' => 'BDT',
        'unit_price' => $money('3000.00'),
        'line_subtotal' => $money('6000.00'),
        'line_total' => $money('6000.00'),
        'platform_retained_amount' => $money('5000.00'),
    ]);
}

function storeProfitRaceSettle(int $itemId): void
{
    $eligibility = app(EvaluateOrderProceedsEligibility::class);
    $item = OrderItem::query()->findOrFail($itemId);

    $eligibility->markDelivered($item);
    $eligibility->markCodCollected($item, Money::fromDecimal('6000.00', Currency::BDT));
}

it('credits a store\'s profit exactly once when several workers settle the same line', function () {
    $item = storeProfitRaceLine();

    storeProfitRace(4, fn () => storeProfitRaceSettle($item->id));

    $wallet = storeProfitRaceWallet();
    $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

    expect($settlement->reseller_earning?->toDecimal())->toBe('1000.00')
        ->and($settlement->eligible_at)->not->toBeNull()
        ->and($wallet->total->toDecimal())->toBe('1000.00')
        ->and(LedgerEntry::query()->where('wallet_id', $wallet->id)->count())->toBe(1)
        ->and(LedgerEntry::query()->where('wallet_id', $wallet->id)->sole()->balance_type)->toBe(BalanceType::Withdrawable)
        ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
});

it('never takes back more than was credited when a return races the settlement', function () {
    $item = storeProfitRaceLine();

    // One of the two units has come back.
    $return = OrderReturn::create([
        'order_id' => $item->order_id,
        'business_account_id' => $item->order->business_account_id,
        'source' => OrderStatusChangeSource::Staff,
        'status' => ReturnStatus::Requested,
        'reason' => ReturnReason::Other,
        'requested_at' => now(),
    ]);

    $returnItem = $return->items()->create([
        'order_item_id' => $item->id,
        'quantity' => 1,
        'approved_quantity' => 1,
        'received_quantity' => 1,
    ]);

    storeProfitRace(2, function (int $worker) use ($item, $returnItem) {
        if ($worker === 0) {
            storeProfitRaceSettle($item->id);

            return;
        }

        app(ReverseOrderProceedsSettlement::class)->forReturn(OrderReturnItem::query()->findOrFail($returnItem->id));
    });

    $wallet = storeProfitRaceWallet();
    $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

    // Either order is legitimate: the reversal landed after the credit and took
    // back its half, or it arrived first and found nothing to take back. What
    // can never happen is more being reversed than was credited, a negative
    // wallet, or a ledger that no longer adds up.
    expect($settlement->reseller_earning?->toDecimal())->toBe('1000.00')
        ->and(in_array($wallet->total->toDecimal(), ['500.00', '1000.00'], true))->toBeTrue()
        ->and($settlement->reversed_amount->lessThanOrEqualTo($settlement->reseller_earning))->toBeTrue()
        ->and($wallet->total->plus($settlement->reversed_amount)->toDecimal())->toBe('1000.00')
        ->and($wallet->usableBalance()->isNegative())->toBeFalse()
        ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
});

it('lets only one of a withdrawal and a platform spend have the same money', function () {
    /*
     * ৳300 of platform credit and ৳200 withdrawable. A platform spend of ৳400
     * takes all the platform credit and ৳100 of the withdrawable money; a
     * withdrawal wants ৳200 of the withdrawable money. Both cannot have it. If
     * the spend wins, ৳100 of withdrawable money is left and the withdrawal is
     * refused; if the withdrawal wins, only ৳300 is spendable and the spend is
     * refused. Without the row lock both would read the same balance and succeed.
     */
    $service = app(WalletService::class);

    $service->credit(storeProfitRaceWallet(), LedgerTransactionType::TopUpCredit, Money::fromDecimal('300.00', Currency::BDT), new PostingContext(source: 'test', description: 'Top-up'));
    $service->credit(storeProfitRaceWallet(), LedgerTransactionType::SalesCredit, Money::fromDecimal('200.00', Currency::BDT), new PostingContext(source: 'test', description: 'Earned'));

    storeProfitRace(2, function (int $worker) {
        $service = app(WalletService::class);

        if ($worker === 0) {
            $service->debit(
                storeProfitRaceWallet(),
                LedgerTransactionType::ServiceFeeDebit,
                Money::fromDecimal('400.00', Currency::BDT),
                new PostingContext(source: 'test', description: 'Platform purchase', idempotencyKey: 'race:spend'),
            );

            return;
        }

        $service->reserve(
            storeProfitRaceWallet(),
            LedgerTransactionType::WithdrawalDebit,
            Money::fromDecimal('200.00', Currency::BDT),
            new PostingContext(source: 'test', description: 'Withdrawal', idempotencyKey: 'race:withdraw'),
        );
    });

    $wallet = storeProfitRaceWallet();

    $spent = LedgerEntry::query()->where('wallet_id', $wallet->id)->where('type', LedgerTransactionType::ServiceFeeDebit)->exists();
    $reserved = $wallet->reserved->isPositive();

    expect($spent xor $reserved)->toBeTrue()
        // Nothing ever goes below zero, and platform credit is never reserved.
        ->and($wallet->usableBalance()->isNegative())->toBeFalse()
        ->and($wallet->reservedPlatformCredit()->toDecimal())->toBe('0.00')
        ->and($spent ? $wallet->total->toDecimal() : $wallet->reserved->toDecimal())->toBe($spent ? '100.00' : '200.00')
        ->and(app(VerifyLedgerIntegrity::class)->problemsFor($wallet))->toBe([]);
});
