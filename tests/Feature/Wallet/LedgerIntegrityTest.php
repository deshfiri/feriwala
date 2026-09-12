<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\WalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The reconciliation sweep (P2-9, §28.1).
 *
 * The ledger and the balance are written in one transaction, so they should
 * never diverge. "Should never" is not a control. This is.
 */

function integrityWallet(int ...$movements): Wallet
{
    $wallet = app(OpenWallet::class)->handle(testBusinessAccount(AccountStatus::Active));

    foreach ($movements as $amount) {
        app(WalletService::class)->credit(
            $wallet->refresh(),
            LedgerTransactionType::TopUpCredit,
            Money::of($amount, Currency::BDT),
            new PostingContext(source: 'test', description: 'Top-up'),
        );
    }

    return $wallet->refresh();
}

it('finds nothing wrong with a wallet that was posted to properly', function () {
    integrityWallet(50000, 20000);

    $result = app(VerifyLedgerIntegrity::class)->handle();

    expect($result['checked'])->toBe(1)
        ->and($result['mismatched'])->toBe(0)
        ->and($result['problems'])->toBe([]);
});

it('catches a balance that was written outside the posting service', function () {
    /*
     * The drift this exists to find. Something wrote a wallet column without
     * an entry, and every screen has been showing a figure nobody can account
     * for ever since.
     */
    $wallet = integrityWallet(50000);

    DB::table('wallets')->where('id', $wallet->id)->update(['total_minor' => 99999]);

    $problems = app(VerifyLedgerIntegrity::class)->problemsFor($wallet->refresh());

    expect($problems)->toHaveCount(1)
        ->and($problems[0]['problem'])->toBe('balance_mismatch')
        ->and($problems[0]['stored_minor'])->toBe(99999)
        ->and($problems[0]['derived_minor'])->toBe(50000);
});

it('catches an entry whose own arithmetic is wrong', function () {
    $wallet = integrityWallet(50000);

    // Reaching past the append-only trigger the only way a test can: this is
    // the corruption the sweep has to notice, so it has to be creatable.
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_update');
    DB::table('ledger_entries')->where('wallet_id', $wallet->id)
        ->update(['balance_after_minor' => 12345]);
    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_update');

    $problems = collect(app(VerifyLedgerIntegrity::class)->problemsFor($wallet->refresh()))
        ->pluck('problem');

    expect($problems)->toContain('entry_does_not_balance');
});

it('catches a missing entry through the broken chain', function () {
    /*
     * The check the sums cannot do. Delete an entry and the remaining ones
     * still add up among themselves — what gives it away is that one entry's
     * closing balance is no longer the next one's opening balance.
     */
    $wallet = integrityWallet(50000, 20000, 10000);

    $middle = DB::table('ledger_entries')->where('wallet_id', $wallet->id)
        ->orderBy('id')->skip(1)->first();

    /*
     * Reaching past two guards, because that is what it takes to manufacture
     * this: the ledger refuses deletion, and the lifecycle event pointing at
     * the entry refuses to let it go. Both are doing their job — the corruption
     * being simulated here is one the application cannot cause.
     */
    DB::statement('ALTER TABLE ledger_entries DISABLE TRIGGER ledger_entries_no_delete');
    DB::statement('ALTER TABLE wallet_transaction_events DISABLE TRIGGER wallet_transaction_events_no_delete');

    DB::table('wallet_transaction_events')->where('ledger_entry_id', $middle->id)->delete();
    DB::table('ledger_entries')->where('id', $middle->id)->delete();

    DB::statement('ALTER TABLE wallet_transaction_events ENABLE TRIGGER wallet_transaction_events_no_delete');
    DB::statement('ALTER TABLE ledger_entries ENABLE TRIGGER ledger_entries_no_delete');

    $problems = collect(app(VerifyLedgerIntegrity::class)->problemsFor($wallet->refresh()))
        ->pluck('problem');

    expect($problems)->toContain('broken_chain');
});

it('shouts once per sweep rather than once per row', function () {
    // A ledger drift is one incident however many rows it touched, and an
    // alert that arrives a thousand times is an alert nobody reads.
    $wallet = integrityWallet(50000, 20000, 10000);

    DB::table('wallets')->where('id', $wallet->id)->update(['total_minor' => 1]);

    Log::shouldReceive('channel')->once()->with('wallet')->andReturnSelf();
    Log::shouldReceive('critical')->once()
        ->withArgs(fn (string $message) => str_contains($message, 'Ledger integrity'));

    app(VerifyLedgerIntegrity::class)->handle();
});

it('says nothing at all when everything adds up', function () {
    integrityWallet(50000);

    Log::shouldReceive('channel')->never();

    expect(app(VerifyLedgerIntegrity::class)->handle()['problems'])->toBe([]);
});

it('repairs nothing', function () {
    /*
     * A sweep that silently corrected a balance would destroy the evidence of
     * whatever caused the drift — and §23.2 already has a way to put things
     * right that leaves a record.
     */
    $wallet = integrityWallet(50000);

    DB::table('wallets')->where('id', $wallet->id)->update(['total_minor' => 99999]);

    app(VerifyLedgerIntegrity::class)->handle();

    expect($wallet->refresh()->total_minor->minorUnits)->toBe(99999);
});
