<?php

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Wallets for the accounts that were already trading (P2-1, §23).
 *
 * The case the activation path cannot reach: an account activated before the
 * wallets table existed never gets one, because the thing that opens a wallet is
 * an activation that has already happened.
 *
 * `2026_09_16_140000_open_wallets_for_activated_accounts.php` is a historical
 * migration and is never rewritten (CLAUDE.md). It ran once, against the
 * bigint-poisha schema that existed on the day, and is recorded complete in
 * every real database forever — nothing will ever invoke its `up()` again.
 * D26's later conversion renamed the very columns it inserts
 * (`total_minor` -> `total`, and so on), so re-running it against today's
 * `testing` schema fails on a column that no longer exists there — correctly:
 * that schema never had the shape this migration was written for.
 *
 * This still tests real behaviour, on the schema it actually describes: a
 * throwaway Postgres schema built by running every migration up to and
 * including this one, exactly as it stood the day it ran, following the same
 * pattern `FlatTakaConversionMigrationTest` uses for the migration that
 * later renamed these very columns.
 */

const WALLET_BACKFILL_CONNECTION = 'wallet_backfill_migration';
const WALLET_BACKFILL_MIGRATION = '2026_09_16_140000_open_wallets_for_activated_accounts';

beforeEach(function () {
    $this->schema = 'wallet_backfill_'.Str::lower(Str::random(12));

    config()->set('database.connections.'.WALLET_BACKFILL_CONNECTION, [
        ...config('database.connections.'.config('database.default')),
        'search_path' => $this->schema,
    ]);

    DB::purge(WALLET_BACKFILL_CONNECTION);
    DB::connection(WALLET_BACKFILL_CONNECTION)->statement('CREATE SCHEMA "'.$this->schema.'"');
});

afterEach(function () {
    DB::connection(WALLET_BACKFILL_CONNECTION)->statement('DROP SCHEMA IF EXISTS "'.$this->schema.'" CASCADE');
    DB::purge(WALLET_BACKFILL_CONNECTION);
});

/**
 * Every migration up to and including the backfill, on the throwaway schema.
 */
function walletBackfillMigrateSchema(): void
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

    $upToBackfill = array_filter(
        $files,
        fn (string $name) => strcmp($name, WALLET_BACKFILL_MIGRATION) <= 0,
        ARRAY_FILTER_USE_KEY,
    );

    $migrator->usingConnection(WALLET_BACKFILL_CONNECTION, function () use ($migrator, $upToBackfill) {
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $migrator->run(array_values($upToBackfill));
    });
}

/**
 * Re-run just the backfill migration's `up()`, on the throwaway schema.
 */
function runWalletBackfill(): void
{
    $migrator = app('migrator');

    $migrator->usingConnection(WALLET_BACKFILL_CONNECTION, function () {
        $migration = require database_path(
            'migrations/2026_09_16_140000_open_wallets_for_activated_accounts.php'
        );

        $migration->up();
    });
}

/**
 * A business account, written directly on the throwaway schema at the shape
 * it had on the day this migration ran — not through the current
 * `BusinessAccount` model, which targets today's schema.
 */
function walletBackfillAccount(string $status): int
{
    $conn = DB::connection(WALLET_BACKFILL_CONNECTION);

    $userId = $conn->table('users')->insertGetId([
        'name' => 'Backfill User', 'email' => 'backfill-'.Str::random(8).'@example.test', 'password' => 'x',
        'public_id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $conn->table('business_accounts')->insertGetId([
        'public_id' => (string) Str::ulid(), 'owner_id' => $userId, 'name' => 'Backfill Business',
        'slug' => 'backfill-business-'.Str::lower(Str::random(10)), 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('gives an already activated account the wallet it never got', function () {
    walletBackfillMigrateSchema();
    $accountId = walletBackfillAccount('active');

    runWalletBackfill();

    $wallet = DB::connection(WALLET_BACKFILL_CONNECTION)->table('wallets')
        ->where('business_account_id', $accountId)->firstOrFail();

    expect((int) $wallet->total_minor)->toBe(0)
        ->and((int) $wallet->reserved_minor)->toBe(0)
        ->and($wallet->currency_code)->toBe('BDT');
});

it('opens it empty, with nothing to explain', function () {
    // Every figure in a wallet has to be explained by a ledger entry, and an
    // opening balance would be one that is not.
    walletBackfillMigrateSchema();
    walletBackfillAccount('active');

    runWalletBackfill();

    $conn = DB::connection(WALLET_BACKFILL_CONNECTION);

    expect($conn->table('ledger_entries')->count())->toBe(0)
        ->and($conn->table('wallet_transactions')->count())->toBe(0);
});

it('leaves an account still in onboarding alone', function () {
    // A wallet opens with the activation. One that appeared earlier would be a
    // wallet whose existence says something untrue about the account.
    walletBackfillMigrateSchema();
    $accountId = walletBackfillAccount('kyc_pending');

    runWalletBackfill();

    expect(DB::connection(WALLET_BACKFILL_CONNECTION)->table('wallets')
        ->where('business_account_id', $accountId)->exists())->toBeFalse();
});

it('does not give anybody a second wallet', function () {
    /*
     * It will be re-run — by a repeated deploy, or against an account activated
     * between the deploy and the migration. Two wallets for one account would
     * split its money.
     */
    walletBackfillMigrateSchema();
    $accountId = walletBackfillAccount('active');

    runWalletBackfill();
    runWalletBackfill();

    expect(DB::connection(WALLET_BACKFILL_CONNECTION)->table('wallets')
        ->where('business_account_id', $accountId)->count())->toBe(1);
});
