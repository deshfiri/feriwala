<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Billing\Actions\SettlePayment;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * §43's concurrency tests for payments, with real processes (P2-35).
 *
 * A gateway sends the same notification several times, and it sends them at
 * once — a retry arriving while the first is still being verified is the
 * ordinary case, not the exotic one. Every guarantee the settlement path makes
 * is about that, and a single-process test cannot tell a distributed lock from
 * a comment claiming there is one.
 *
 * These fork real workers that race to settle the same payment, and then ask the
 * database what happened. The machinery is the same as the wallet's (P2-10) and
 * for the same two reasons: the workers must be able to *see* the payment, so
 * the data has to be committed; and a forked child must not share its parent's
 * database socket.
 */

beforeEach(function () {
    config()->set('database.connections.payment_race', config('database.connections.pgsql'));
    config()->set('database.default', 'payment_race');

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->wallet = app(OpenWallet::class)->handle($this->account);

    $this->payment = Payment::create([
        'business_account_id' => $this->account->id,
        'purpose' => PaymentPurpose::WalletTopUp,
        'status' => PaymentStatus::Initiated,
        'amount' => Money::fromDecimal('2500.00', Currency::BDT),
        'currency_code' => 'BDT',
        'gateway' => 'sslcommerz',
    ]);
});

afterEach(function () {
    /*
     * Committed rows do not roll back with the test. The append-only triggers
     * have to come off to clear them: the ledger refuses deletion by design,
     * which is the point of it, and this is the one place allowed to reach past
     * it.
     */
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_delete');
    DB::statement('ALTER TABLE wallet_transaction_events DISABLE TRIGGER wallet_transaction_events_no_delete');

    /*
     * `payment_logs` needs no trigger disabled: it refuses deletion at the
     * model rather than in the database, and a query-builder delete never
     * reaches the model. The ledger's refusal is a real trigger, which is why
     * only those two come off.
     */
    /*
     * Every payment this account has, not just the one the setup made — a test
     * may create a second, and the ledger entries pointing at it have to go
     * before it does. `payments` is deleted last for the same reason: its
     * foreign keys null themselves out of the ledger on the way, and the ledger
     * refuses an update.
     */
    $payments = DB::table('payments')
        ->where('business_account_id', $this->account->id)
        ->pluck('id')
        ->all();

    DB::table('payment_logs')->whereIn('payment_id', $payments)->delete();
    DB::table('wallet_transaction_events')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('payments')->whereIn('id', $payments)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();
    DB::table('business_accounts')->where('id', $this->account->id)->delete();
    DB::table('users')->where('id', $this->account->owner_id)->delete();

    DB::statement('ALTER TABLE wallet_transaction_events ENABLE TRIGGER wallet_transaction_events_no_delete');
    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 *
 * The children are killed rather than exited: an ordinary shutdown would run
 * PHPUnit's handlers and tear down connection objects the child only inherited,
 * and the parent is still using those.
 */
function paymentRace(int $count, Closure $work): void
{
    DB::purge('payment_race');

    // A shared starting gun, so the workers actually overlap instead of
    // finishing one by one in the time it takes to fork the next.
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
                // Losing the race is the expected outcome for all but one
                // worker. What happened is read out of the database
                // afterwards, not out of an exit code.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('settles once when six notifications arrive at the same moment', function () {
    /*
     * The failure this exists to rule out: six IPNs verify concurrently, all six
     * read the payment as unsettled, and the wallet is credited six times for
     * one payment of real money.
     *
     * Three things stand in the way, and this asserts the combination rather
     * than any one of them: the distributed lock around settlement, the status
     * re-read inside it, and the posting's idempotency key derived from the
     * payment's own reference.
     */
    $reference = $this->payment->reference;
    $paymentId = $this->payment->id;

    paymentRace(6, function () use ($reference, $paymentId) {
        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $reference,
            'currency_amount' => '2500.00',
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK-RACE',
        ])]);

        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($paymentId);

        app(SettlePayment::class)->handle($payment, 'val-race');
    });

    /** @var Payment $payment */
    $payment = Payment::query()->findOrFail($paymentId);

    /** @var Wallet $wallet */
    $wallet = Wallet::query()->findOrFail($this->wallet->id);

    $credits = LedgerEntry::query()->where('wallet_id', $this->wallet->id)->count();

    expect($payment->status)->toBe(PaymentStatus::Paid)

        // Credited once, not six times.
        ->and($wallet->total->toDecimal())->toBe('2500.00')
        ->and($credits)->toBe(1);
});

it('lets only one payment claim a provider transaction under a race', function () {
    /*
     * Two payments, one real transaction at the provider — the shape of a
     * misdirected or replayed notification. One of them must settle and the
     * other must not, and the unique index is what decides it when two
     * processes both pass the application's check.
     */
    $second = Payment::create([
        'business_account_id' => $this->account->id,
        'purpose' => PaymentPurpose::WalletTopUp,
        'status' => PaymentStatus::Initiated,
        'amount' => Money::fromDecimal('2500.00', Currency::BDT),
        'currency_code' => 'BDT',
        'gateway' => 'sslcommerz',
    ]);

    $ids = [$this->payment->id, $second->id];
    $references = [$this->payment->reference, $second->reference];

    paymentRace(2, function (int $worker) use ($ids, $references) {
        Http::fake(['*' => Http::response([
            'status' => 'VALID',
            'tran_id' => $references[$worker],
            'currency_amount' => '2500.00',
            'currency_type' => 'BDT',
            'bank_tran_id' => 'BANK-SHARED',
        ])]);

        /** @var Payment $payment */
        $payment = Payment::query()->findOrFail($ids[$worker]);

        // The same provider transaction, offered to both.
        app(SettlePayment::class)->handle($payment, 'val-shared');
    });

    $settled = Payment::query()
        ->whereIn('id', $ids)
        ->where('status', PaymentStatus::Paid)
        ->count();

    expect($settled)->toBe(1);
});
