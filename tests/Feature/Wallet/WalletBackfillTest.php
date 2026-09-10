<?php

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;

/*
 * Wallets for the accounts that were already trading (P2-1, §23).
 *
 * The case the activation path cannot reach: an account activated before the
 * wallets table existed never gets one, because the thing that opens a wallet is
 * an activation that has already happened.
 */

/**
 * Run the backfill against the current schema.
 *
 * The migration itself, not a copy of its logic — a test that reimplemented the
 * query would pass while the migration was wrong.
 */
function runWalletBackfill(): void
{
    $migration = require database_path(
        'migrations/2026_09_16_140000_open_wallets_for_activated_accounts.php'
    );

    $migration->up();
}

it('gives an already activated account the wallet it never got', function () {
    $account = testBusinessAccount(AccountStatus::Active);

    // As it would have been before P2-1: activated, with no wallet.
    Wallet::query()->where('business_account_id', $account->id)->delete();

    runWalletBackfill();

    $wallet = Wallet::query()->where('business_account_id', $account->id)->firstOrFail();

    expect($wallet->total_minor->minorUnits)->toBe(0)
        ->and($wallet->reserved_minor->minorUnits)->toBe(0)
        ->and($wallet->currency_code)->toBe('BDT');
});

it('opens it empty, with nothing to explain', function () {
    // Every figure in a wallet has to be explained by a ledger entry, and an
    // opening balance would be one that is not.
    $account = testBusinessAccount(AccountStatus::Active);
    Wallet::query()->where('business_account_id', $account->id)->delete();

    runWalletBackfill();

    expect(DB::table('ledger_entries')->count())->toBe(0)
        ->and(DB::table('wallet_transactions')->count())->toBe(0);
});

it('leaves an account still in onboarding alone', function () {
    // A wallet opens with the activation. One that appeared earlier would be a
    // wallet whose existence says something untrue about the account.
    $account = testBusinessAccount(AccountStatus::KycPending);

    runWalletBackfill();

    expect(Wallet::query()->where('business_account_id', $account->id)->exists())->toBeFalse();
});

it('does not give anybody a second wallet', function () {
    /*
     * It will be re-run — by a repeated deploy, or against an account activated
     * between the deploy and the migration. Two wallets for one account would
     * split its money.
     */
    $account = testBusinessAccount(AccountStatus::Active);

    runWalletBackfill();
    runWalletBackfill();

    expect(Wallet::query()->where('business_account_id', $account->id)->count())->toBe(1);
});
