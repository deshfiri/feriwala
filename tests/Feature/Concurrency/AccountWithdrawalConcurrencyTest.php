<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Domain\Withdrawal\Actions\AdvanceAccountWithdrawalStatus;
use App\Domain\Withdrawal\Actions\RejectOrFailAccountWithdrawal;
use App\Domain\Withdrawal\Actions\RequestAccountWithdrawal;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/*
 * Real-process concurrency proofs for the Client/Partner withdrawal flow
 * (§27), in the same shape as {@see SupplierWalletConcurrencyTest}: real
 * `pcntl_fork()` workers racing for the same row, on a connection whose
 * writes actually commit, asked afterward what happened rather than trusted
 * at design time.
 */
beforeEach(function () {
    config()->set('database.connections.account_withdrawal_race', config('database.connections.pgsql'));
    config()->set('database.default', 'account_withdrawal_race');

    $this->account = BusinessAccount::factory()->onboarding(AccountStatus::Active)->create();
    $this->wallet = app(OpenWallet::class)->handle($this->account);
});

afterEach(function () {
    DB::statement('ALTER TABLE wallet_transaction_events DISABLE TRIGGER wallet_transaction_events_no_delete');
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_delete');
    DB::statement('ALTER TABLE account_withdrawal_status_history DISABLE TRIGGER account_withdrawal_status_history_no_delete');
    DB::statement('ALTER TABLE account_withdrawals DISABLE TRIGGER account_withdrawals_never_deleted');
    DB::statement('ALTER TABLE payout_methods DISABLE TRIGGER payout_methods_never_deleted');

    // wallet_transaction_events.ledger_entry_id is a RESTRICT (non-cascading)
    // FK, so the event rows have to go before the ledger entries they point
    // to -- otherwise the ledger delete below fails.
    DB::table('wallet_transaction_events')
        ->whereIn('wallet_transaction_id', DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->pluck('id'))
        ->delete();
    DB::table('ledger_entries')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('account_withdrawal_status_history')
        ->whereIn('account_withdrawal_id', DB::table('account_withdrawals')->where('wallet_id', $this->wallet->id)->pluck('id'))
        ->delete();
    DB::table('account_withdrawals')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('wallet_transactions')->where('wallet_id', $this->wallet->id)->delete();
    DB::table('payout_methods')->where('owner_type', PayoutOwnerType::BusinessAccount->value)->where('owner_id', $this->account->id)->delete();
    DB::table('wallets')->where('id', $this->wallet->id)->delete();

    // The BusinessAccount fixture itself is left behind rather than deleted,
    // the same accepted trade-off SupplierWalletConcurrencyTest already makes
    // for its Supplier fixture -- there is no "never delete" trigger on
    // business_accounts, but its memberships and referral rows would need
    // their own cleanup for no benefit this test needs.

    DB::statement('ALTER TABLE payout_methods ENABLE TRIGGER payout_methods_never_deleted');
    DB::statement('ALTER TABLE account_withdrawals ENABLE TRIGGER account_withdrawals_never_deleted');
    DB::statement('ALTER TABLE account_withdrawal_status_history ENABLE TRIGGER account_withdrawal_status_history_no_delete');
    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_delete');
    DB::statement('ALTER TABLE wallet_transaction_events ENABLE TRIGGER wallet_transaction_events_no_delete');
});

/**
 * Run one piece of work in `$count` real processes, started together.
 */
function accountWithdrawalRace(int $count, Closure $work): void
{
    DB::purge('account_withdrawal_race');

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
                // Losing the race is the expected outcome for one side of
                // every test here. What happened is read back from the
                // database afterwards, never from an exit code.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function accountWithdrawalRaceWallet(): Wallet
{
    /** @var Wallet $wallet */
    $wallet = Wallet::query()->findOrFail(test()->wallet->id);

    return $wallet;
}

function accountWithdrawalRacePayoutMethod(): PayoutMethod
{
    return PayoutMethod::create([
        'owner_type' => PayoutOwnerType::BusinessAccount->value,
        'owner_id' => test()->account->id,
        'type' => PayoutMethodType::Bkash,
        'label' => 'Race fixture',
        'details' => ['account_holder_name' => 'Race', 'account_number' => '01711112222'],
        'last_four' => '2222',
        'fingerprint' => hash('sha256', 'account-race-fixture-'.test()->account->id),
        'is_default' => true,
        'status' => PayoutMethodStatus::Active,
    ]);
}

it('reserves only what is available when two withdrawal requests compete for the same balance', function () {
    app(WalletService::class)->credit(
        accountWithdrawalRaceWallet(),
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal('1000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    $method = accountWithdrawalRacePayoutMethod();
    $account = $this->account;

    accountWithdrawalRace(2, function (int $worker) use ($account, $method) {
        app(RequestAccountWithdrawal::class)->handle(
            $account->fresh(),
            accountWithdrawalRaceWallet(),
            $method->fresh(),
            Money::fromDecimal('700.00', Currency::BDT),
            'race:withdrawal:'.$worker,
        );
    });

    $wallet = accountWithdrawalRaceWallet();

    // Only one of the two 700.00 requests could fit inside 1000.00 available.
    expect($wallet->reserved->toDecimal())->toBe('700.00')
        ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('300.00')
        ->and(AccountWithdrawal::query()->where('wallet_id', $wallet->id)->count())->toBe(1);
});

it('never double-decides a withdrawal when approval races rejection', function () {
    app(WalletService::class)->credit(
        accountWithdrawalRaceWallet(),
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal('1000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    $method = accountWithdrawalRacePayoutMethod();

    $withdrawal = app(RequestAccountWithdrawal::class)->handle(
        $this->account->fresh(),
        accountWithdrawalRaceWallet(),
        $method,
        Money::fromDecimal('500.00', Currency::BDT),
        'race:withdrawal:decision',
    );

    $staff = User::factory()->create();
    $withdrawal = app(AdvanceAccountWithdrawalStatus::class)->handle($withdrawal, AccountWithdrawalStatus::UnderReview, $staff->id);

    accountWithdrawalRace(2, function (int $worker) use ($withdrawal, $staff) {
        if ($worker === 0) {
            app(AdvanceAccountWithdrawalStatus::class)->handle($withdrawal->fresh(), AccountWithdrawalStatus::Approved, $staff->id);
        } else {
            app(RejectOrFailAccountWithdrawal::class)->handle($withdrawal->fresh(), AccountWithdrawalStatus::Rejected, 'Race fixture rejection.', $staff->id);
        }
    });

    $fresh = $withdrawal->fresh();
    $wallet = accountWithdrawalRaceWallet();

    // Exactly one of the two decisions won. Whichever it was, the reserved
    // bucket agrees with it: still held for Approved, given back for
    // Rejected -- never both, never neither.
    expect(in_array($fresh->status, [AccountWithdrawalStatus::Approved, AccountWithdrawalStatus::Rejected], true))->toBeTrue();

    if ($fresh->status === AccountWithdrawalStatus::Approved) {
        expect($wallet->reserved->toDecimal())->toBe('500.00');
    } else {
        expect($wallet->reserved->toDecimal())->toBe('0.00');
    }

    // Requested -> UnderReview -> (Approved | Rejected): three rows, never four.
    expect($fresh->statusHistory()->count())->toBe(3);
})->group('slow');

it('never lets a top-up credit and a withdrawal reservation together overdraw the wallet', function () {
    app(WalletService::class)->credit(
        accountWithdrawalRaceWallet(),
        LedgerTransactionType::TopUpCredit,
        Money::fromDecimal('1000.00', Currency::BDT),
        new PostingContext(source: 'test', description: 'Opening'),
    );

    $method = accountWithdrawalRacePayoutMethod();
    $account = $this->account;
    $wallets = app(WalletService::class);

    accountWithdrawalRace(2, function (int $worker) use ($wallets, $account, $method) {
        if ($worker === 0) {
            $wallets->debit(
                accountWithdrawalRaceWallet(),
                LedgerTransactionType::PlatformFeeDebit,
                Money::fromDecimal('700.00', Currency::BDT),
                new PostingContext(source: 'test', description: 'Fee'),
            );
        } else {
            app(RequestAccountWithdrawal::class)->handle(
                $account->fresh(),
                accountWithdrawalRaceWallet(),
                $method->fresh(),
                Money::fromDecimal('700.00', Currency::BDT),
                'race:withdrawal:overdraw',
            );
        }
    });

    $wallet = accountWithdrawalRaceWallet();

    // Whichever ran first took the full 700.00 from the 1000.00 available;
    // the other found only 300.00 left and refused rather than overdrawing.
    // The wallet's own invariants hold regardless of which one won.
    expect($wallet->reserved->toDecimal())->toBeLessThanOrEqual($wallet->total->toDecimal())
        ->and((float) $wallet->total->toDecimal())->toBeGreaterThanOrEqual(0);

    $entries = LedgerEntry::query()->where('wallet_id', $wallet->id)->orderBy('id')->get();

    // Opening credit, plus exactly one entry for the debit if it won the
    // race -- the reservation writes no ledger entry of its own (§23.1), and
    // whichever operation lost found insufficient balance and posted nothing.
    expect($entries->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2);

    foreach ($entries as $index => $entry) {
        if ($index === 0) {
            continue;
        }

        $previous = $entries[$index - 1];
        expect($entry->balance_before->toDecimal())->toBe($previous->balance_after->toDecimal());
    }
});
