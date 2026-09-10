<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * §43's concurrency tests, with real processes (P2-10).
 *
 * Every guarantee the wallet makes is about what happens when two things arrive
 * at once, and a single-process test cannot tell a row lock from a comment
 * claiming there is one. These fork actual workers that race for the same
 * wallet, and then ask the database what happened.
 *
 * Two things follow from that and explain the machinery below:
 *
 *   1. The workers have to be able to *see* the wallet, so the data must be
 *      committed. `RefreshDatabase` wraps the default connection in a
 *      transaction that is never committed, so these tests run on a connection
 *      of their own — and clean up after themselves, because what they write
 *      outlives the test.
 *   2. A forked child must not share its parent's database socket. The parent
 *      lets go of the connection before forking, and each child opens its own.
 */

beforeEach(function () {
    /*
     * A connection whose writes actually land, made the default so the models
     * and the posting service use it without being told.
     */
    config()->set('database.connections.wallet_race', config('database.connections.pgsql'));
    config()->set('database.default', 'wallet_race');

    $this->account = testBusinessAccount(AccountStatus::Active);
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

afterEach(function () {
    /*
     * Committed rows do not roll back with the test. Left behind, they would be
     * a wallet every later test in this process can see — including the
     * integrity sweep, which counts every wallet there is.
     *
     * The append-only triggers have to come off to do it: the ledger refuses
     * deletion by design, which is the point of it, and this is the one place
     * that is allowed to reach past it.
     */
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_delete');

    DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();
    DB::table('business_accounts')->where('id', $this->account->id)->delete();
    DB::table('users')->where('id', $this->account->owner_id)->delete();

    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 *
 * The children are killed rather than exited: an ordinary shutdown would run
 * PHPUnit's handlers and tear down connection objects the child only inherited,
 * and the parent is still using those.
 */
function walletRace(int $count, Closure $work): void
{
    // Nothing live to inherit. Two processes taking turns on one socket
    // corrupt both halves of the conversation.
    DB::purge('wallet_race');

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
                // Being refused is the expected outcome for half of these
                // workers. What actually happened is read out of the database
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

function walletRaceService(): WalletService
{
    return app(WalletService::class);
}

function walletRaceWallet(): Wallet
{
    /** @var Wallet $wallet */
    $wallet = Wallet::query()->findOrFail(test()->wallet->id);

    return $wallet;
}

function walletRaceEntries(): int
{
    return LedgerEntry::query()->where('wallet_id', test()->wallet->id)->count();
}

it('cannot be made to overdraw by parallel debits', function () {
    /*
     * Five workers, three of them affordable. Without the row lock and the
     * re-check inside it, all five read the same balance, all five decide they
     * can afford it, and the wallet ends up owing money nobody lent it.
     */
    walletRaceService()->credit(
        walletRaceWallet(),
        LedgerTransactionType::TopUpCredit,
        Money::of(10000, Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    walletRace(5, function (int $worker) {
        walletRaceService()->debit(
            walletRaceWallet(),
            LedgerTransactionType::ServiceFeeDebit,
            Money::of(3000, Currency::BDT),
            new PostingContext(
                source: 'test',
                description: 'Charge '.$worker,
                idempotencyKey: 'race:debit:'.$worker,
            ),
        );
    });

    $wallet = walletRaceWallet();

    // Three succeeded, two were refused, and the balance says exactly that.
    expect($wallet->total_minor->minorUnits)->toBe(1000)
        ->and($wallet->usableBalance()->isNegative())->toBeFalse()
        ->and(walletRaceEntries())->toBe(4);
});

it('loses none of a burst of parallel credits', function () {
    /*
     * The other half of the same guarantee. A lost update here is money that
     * somebody paid in and the wallet never heard about — six read-modify-writes
     * against one row, and a read outside a lock would quietly discard most of
     * them.
     */
    walletRace(6, function (int $worker) {
        walletRaceService()->credit(
            walletRaceWallet(),
            LedgerTransactionType::TopUpCredit,
            Money::of(2500, Currency::BDT),
            new PostingContext(
                source: 'test',
                description: 'Top-up '.$worker,
                idempotencyKey: 'race:credit:'.$worker,
            ),
        );
    });

    expect(walletRaceWallet()->total_minor->minorUnits)->toBe(15000)
        ->and(walletRaceEntries())->toBe(6);
});

it('posts one command once however many workers send it', function () {
    /*
     * The same command retried by four callers at the same instant — a gateway
     * with an enthusiastic retry policy. The fast path cannot settle this on its
     * own: all four can pass the "has this been posted?" check before any of
     * them writes. The unique index settles it, and the loser is handed the
     * winner's transaction instead of an error.
     */
    walletRace(4, function () {
        walletRaceService()->credit(
            walletRaceWallet(),
            LedgerTransactionType::TopUpCredit,
            Money::of(7500, Currency::BDT),
            new PostingContext(
                source: 'test',
                description: 'The same top-up',
                idempotencyKey: 'race:one-command',
            ),
        );
    });

    expect(walletRaceWallet()->total_minor->minorUnits)->toBe(7500)
        ->and(walletRaceEntries())->toBe(1)
        ->and(WalletTransaction::query()->where('idempotency_key', 'race:one-command')->count())->toBe(1);
});

it('captures a reservation exactly once', function () {
    /*
     * Two workers capture the same reservation. Capturing twice would debit the
     * money twice for one charge and leave the reserved bucket below zero.
     */
    walletRaceService()->credit(
        walletRaceWallet(),
        LedgerTransactionType::TopUpCredit,
        Money::of(20000, Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    $claim = walletRaceService()->reserve(
        walletRaceWallet(),
        LedgerTransactionType::ServiceFeeDebit,
        Money::of(5000, Currency::BDT),
        new PostingContext(source: 'test', description: 'Reserved for a charge'),
    );

    walletRace(2, function () use ($claim) {
        walletRaceService()->capture($claim->fresh());
    });

    $wallet = walletRaceWallet();

    expect($wallet->total_minor->minorUnits)->toBe(15000)
        ->and($wallet->reserved_minor->minorUnits)->toBe(0)
        ->and(walletRaceEntries())->toBe(2);
});

it('leaves a ledger that still adds up after a race', function () {
    /*
     * The check that would catch what the counts above cannot: every entry's
     * closing balance has to be the next one's opening balance. Interleaved
     * postings that each looked right on their own would break the chain, and
     * the statement would stop making sense from that row on.
     */
    walletRace(5, function (int $worker) {
        walletRaceService()->credit(
            walletRaceWallet(),
            LedgerTransactionType::TopUpCredit,
            Money::of(1000 * ($worker + 1), Currency::BDT),
            new PostingContext(
                source: 'test',
                description: 'Top-up '.$worker,
                idempotencyKey: 'race:chain:'.$worker,
            ),
        );
    });

    expect(app(VerifyLedgerIntegrity::class)->problemsFor(walletRaceWallet()))->toBe([])
        ->and(walletRaceWallet()->total_minor->minorUnits)->toBe(15000);
});
