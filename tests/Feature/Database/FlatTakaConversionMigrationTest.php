<?php

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Converting every stored money value from integer minor units to exact flat
 * Taka, and back (D26, `2026_10_05_100000_convert_monetary_storage_to_flat_taka`).
 *
 * Every other test only ever sees this migration already applied, as part of
 * the normal migration set. This one builds the exact pre-D26 schema in a
 * throwaway Postgres schema of its own, puts rows in it the way the platform
 * actually wrote them before D26 -- across the tables most exercised by the
 * conversion's genuinely dangerous edge cases (a value at bigint's own
 * maximum, a negative reversal, a fractional amount, the cross-column
 * arithmetic CHECK constraints, the immutability guards) -- and runs the
 * migration up and down over real rows.
 *
 * The schema is created on a connection of its own, so nothing here runs
 * inside the test transaction or touches the shared `testing` schema, and it
 * is dropped afterwards (§ "Tests" in CLAUDE.md).
 */

const FLAT_TAKA_CONNECTION = 'flat_taka_migration';
const FLAT_TAKA_PRE_MIGRATION = '2026_10_04_130000_add_supplier_withdrawal_limit_overrides';
const FLAT_TAKA_MIGRATION = '2026_10_05_100000_convert_monetary_storage_to_flat_taka';

beforeEach(function () {
    $this->schema = 'flat_taka_'.Str::lower(Str::random(12));

    config()->set('database.connections.'.FLAT_TAKA_CONNECTION, [
        ...config('database.connections.'.config('database.default')),
        'search_path' => $this->schema,
    ]);

    DB::purge(FLAT_TAKA_CONNECTION);
    DB::connection(FLAT_TAKA_CONNECTION)->statement('CREATE SCHEMA "'.$this->schema.'"');
});

afterEach(function () {
    DB::connection(FLAT_TAKA_CONNECTION)->statement('DROP SCHEMA IF EXISTS "'.$this->schema.'" CASCADE');
    DB::purge(FLAT_TAKA_CONNECTION);
});

/**
 * Every migration the application runs, by name.
 *
 * @return array<string, string>
 */
function flatTakaMigrationFiles(): array
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    return $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
}

/**
 * @param  array<string, string>  $files
 */
function flatTakaMigrate(array $files): void
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $migrator->usingConnection(FLAT_TAKA_CONNECTION, function () use ($migrator, $files) {
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $migrator->run(array_values($files));
    });
}

/**
 * @param  array<string, string>  $files
 */
function flatTakaRollBack(array $files): void
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $migrator->usingConnection(FLAT_TAKA_CONNECTION, fn () => $migrator->rollback(array_values($files)));
}

/**
 * Bring the throwaway schema to exactly the pre-D26 shape: every migration up
 * to and including the one immediately before the conversion, no further.
 *
 * @return array<string, string> every migration, by name
 */
function flatTakaMigrateToPreD26(): array
{
    $files = flatTakaMigrationFiles();

    $after = array_keys(array_filter(
        $files,
        fn (string $name) => strcmp($name, FLAT_TAKA_PRE_MIGRATION) > 0,
        ARRAY_FILTER_USE_KEY,
    ));
    expect($after[0] ?? null)->toBe(FLAT_TAKA_MIGRATION);

    flatTakaMigrate(array_filter(
        $files,
        fn (string $name) => strcmp($name, FLAT_TAKA_PRE_MIGRATION) <= 0,
        ARRAY_FILTER_USE_KEY,
    ));

    expect(flatTakaColumn('wallets', 'total_minor'))->not->toBeNull()
        ->and(flatTakaColumn('wallets', 'total'))->toBeNull();

    return $files;
}

/**
 * @return object{data_type: string, is_nullable: string, column_default: string|null}|null
 */
function flatTakaColumn(string $table, string $column): ?object
{
    return DB::connection(FLAT_TAKA_CONNECTION)->selectOne(
        'SELECT data_type, is_nullable, column_default FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        [$table, $column],
    );
}

function flatTakaValue(string $table, string $idColumn, int $id, string $column): ?string
{
    $value = DB::connection(FLAT_TAKA_CONNECTION)->table($table)->where($idColumn, $id)->value($column);

    return $value === null ? null : (string) $value;
}

function flatTakaTriggerDefinition(string $table, string $trigger): ?string
{
    $row = DB::connection(FLAT_TAKA_CONNECTION)->selectOne(
        'SELECT pg_get_triggerdef(t.oid) AS def
           FROM pg_trigger t
           JOIN pg_class c ON c.oid = t.tgrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = current_schema() AND c.relname = ? AND t.tgname = ?',
        [$table, $trigger],
    );

    return $row?->def;
}

function flatTakaFunctionDefinition(string $name): ?string
{
    $row = DB::connection(FLAT_TAKA_CONNECTION)->selectOne(
        'SELECT pg_get_functiondef(p.oid) AS def
           FROM pg_proc p
           JOIN pg_namespace n ON n.oid = p.pronamespace
          WHERE n.nspname = current_schema() AND p.proname = ?',
        [$name],
    );

    return $row?->def;
}

/**
 * A minimal user + business account, on the throwaway connection.
 *
 * @return array{user: int, business_account: int}
 */
function flatTakaAccount(string $suffix): array
{
    $conn = DB::connection(FLAT_TAKA_CONNECTION);

    $userId = $conn->table('users')->insertGetId([
        'name' => "Flat Taka {$suffix}", 'email' => "flat-taka-{$suffix}@example.test", 'password' => 'x',
        'public_id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $businessAccountId = $conn->table('business_accounts')->insertGetId([
        'public_id' => (string) Str::ulid(), 'owner_id' => $userId, 'name' => "Flat Taka Business {$suffix}",
        'slug' => 'flat-taka-business-'.Str::lower(Str::random(10)), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['user' => $userId, 'business_account' => $businessAccountId];
}

/**
 * Rows written the pre-D26 way: `_minor` columns, integer poisha. Covers
 * zero, whole Taka, one poisha, a fractional amount, a negative reversal,
 * and bigint's own maximum -- the largest value the old column type could
 * ever have actually held.
 *
 * @return array{wallet_zero: int, wallet_whole: int, wallet_one_poisha: int, wallet_max: int, ledger: int, reversal: int, order: int}
 */
function flatTakaSeedPreD26Rows(): array
{
    $conn = DB::connection(FLAT_TAKA_CONNECTION);

    $walletZero = flatTakaAccount('wallet-zero');
    $walletWhole = flatTakaAccount('wallet-whole');
    $walletOnePoisha = flatTakaAccount('wallet-one-poisha');
    $walletMax = flatTakaAccount('wallet-max');
    $orderAccount = flatTakaAccount('order');

    $wallet = fn (int $businessAccountId, int $totalMinor) => $conn->table('wallets')->insertGetId([
        'public_id' => (string) Str::ulid(), 'business_account_id' => $businessAccountId, 'currency_code' => 'BDT',
        'total_minor' => $totalMinor, 'required_deposit_minor' => 0, 'reserved_minor' => 0,
        'pending_minor' => 0, 'hold_minor' => 0, 'cod_receivable_minor' => 0, 'minimum_balance_minor' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ids = [
        'wallet_zero' => $wallet($walletZero['business_account'], 0),
        'wallet_whole' => $wallet($walletWhole['business_account'], 100000), // 1000.00 Taka
        'wallet_one_poisha' => $wallet($walletOnePoisha['business_account'], 1), // 0.01 Taka
        // bigint's own maximum: 92233720368547758.07 Taka, 17 integer digits --
        // exactly Money's own ceiling and the value that exposed a real
        // precision-loss bug in Postgres's numeric division during development.
        'wallet_max' => $wallet($walletMax['business_account'], 9223372036854775807),
    ];

    $ledgerWalletId = $ids['wallet_zero'];
    $ids['ledger'] = $conn->table('ledger_entries')->insertGetId([
        'public_id' => (string) Str::ulid(), 'reference' => 'LDG-TEST-'.Str::random(8),
        'wallet_id' => $ledgerWalletId, 'business_account_id' => $walletZero['business_account'], 'currency_code' => 'BDT',
        'type' => 'credit', 'source' => 'test',
        'debit_minor' => 0, 'credit_minor' => 12345, // 123.45 fractional Taka
        'balance_before_minor' => 0, 'balance_after_minor' => 12345,
        'status' => 'posted', 'description' => 'fixture: fractional Taka', 'created_at' => now(),
    ]);

    $ids['reversal'] = $conn->table('wallet_transactions')->insertGetId([
        'public_id' => (string) Str::ulid(), 'reference' => 'WTX-TEST-'.Str::random(8),
        'wallet_id' => $ledgerWalletId, 'business_account_id' => $walletZero['business_account'], 'currency_code' => 'BDT',
        'type' => 'reversal', 'direction' => 'debit', 'source' => 'test',
        'amount_minor' => -2550, // -25.50 negative reversal
        'status' => 'posted', 'description' => 'fixture: negative reversal',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $paymentId = $conn->table('payments')->insertGetId([
        'public_id' => (string) Str::ulid(), 'reference' => 'PAY-TEST-'.Str::random(8),
        'business_account_id' => $orderAccount['business_account'], 'purpose' => 'wholesale_order', 'status' => 'draft',
        'gateway' => 'sslcommerz', 'amount_minor' => 16000, 'revenue_minor' => 16000, 'currency_code' => 'BDT',
        'idempotency_key' => 'flat-taka-payment-'.Str::random(8), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $address = json_encode([
        'contact_name' => 'x', 'contact_mobile' => '01712345678', 'line_1' => 'x',
        'area' => 'x', 'city' => 'x', 'district' => 'x', 'postcode' => '1216', 'country' => 'BD',
    ]);

    $ids['order'] = $conn->table('orders')->insertGetId([
        'public_id' => (string) Str::ulid(), 'reference' => 'ORD-TEST-'.Str::random(8), 'source' => 'erp_wholesale',
        'status' => 'payment_pending', 'business_account_id' => $orderAccount['business_account'], 'placed_by' => $orderAccount['user'],
        'payment_id' => $paymentId, 'idempotency_key' => 'flat-taka-order-'.Str::random(8),
        'customer' => json_encode(['business_name' => 'x', 'contact_name' => 'x', 'email' => 'x@example.test', 'mobile' => '01712345678']),
        'billing_address' => $address, 'shipping_address' => $address, 'currency_code' => 'BDT',
        // The full arithmetic chain orders_total_adds_up enforces:
        // total = subtotal - discount + delivery + tax + cod_fee.
        'subtotal_minor' => 10050, 'discount_minor' => 50, 'delivery_minor' => 6000,
        'tax_minor' => 0, 'tax_included_minor' => 0, 'cod_fee_minor' => 0, 'total_minor' => 16000,
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $ids;
}

it('converts a fresh, empty schema with no data to move', function () {
    $files = flatTakaMigrationFiles();
    flatTakaMigrate($files);

    $remaining = DB::connection(FLAT_TAKA_CONNECTION)->selectOne(
        "SELECT count(*) AS n FROM information_schema.columns WHERE table_schema = current_schema() AND column_name LIKE '%\\_minor'",
    );
    expect((int) $remaining->n)->toBe(0);

    $type = flatTakaColumn('wallets', 'total');
    expect($type)->not->toBeNull()
        ->and($type->data_type)->toBe('numeric');
});

it('converts a populated pre-D26 schema exactly: zero, whole Taka, one poisha, fractional, negative reversal, and bigint\'s own maximum', function () {
    $files = flatTakaMigrateToPreD26();
    $ids = flatTakaSeedPreD26Rows();

    flatTakaMigrate([FLAT_TAKA_MIGRATION => $files[FLAT_TAKA_MIGRATION]]);

    expect(flatTakaValue('wallets', 'id', $ids['wallet_zero'], 'total'))->toBe('0.00')
        ->and(flatTakaValue('wallets', 'id', $ids['wallet_whole'], 'total'))->toBe('1000.00')
        ->and(flatTakaValue('wallets', 'id', $ids['wallet_one_poisha'], 'total'))->toBe('0.01')
        // The value that exposed the numeric-division precision bug: dividing
        // by 100 silently returned scale 0 for a 19-digit numerator. This
        // migration multiplies by 0.01 instead, which does not have that
        // failure mode at any magnitude.
        ->and(flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total'))->toBe('92233720368547758.07')
        ->and(flatTakaValue('ledger_entries', 'id', $ids['ledger'], 'credit'))->toBe('123.45')
        ->and(flatTakaValue('ledger_entries', 'id', $ids['ledger'], 'balance_after'))->toBe('123.45')
        ->and(flatTakaValue('wallet_transactions', 'id', $ids['reversal'], 'amount'))->toBe('-25.50')
        ->and(flatTakaValue('orders', 'id', $ids['order'], 'subtotal'))->toBe('100.50')
        ->and(flatTakaValue('orders', 'id', $ids['order'], 'discount'))->toBe('0.50')
        ->and(flatTakaValue('orders', 'id', $ids['order'], 'delivery'))->toBe('60.00')
        ->and(flatTakaValue('orders', 'id', $ids['order'], 'total'))->toBe('160.00');

    $walletType = flatTakaColumn('wallets', 'total');
    expect($walletType->data_type)->toBe('numeric')
        ->and($walletType->column_default)->toBe('0.00');

    // The cross-column arithmetic CHECK constraint (subtotal - discount +
    // delivery + tax + cod_fee = total) still holds after conversion, and
    // still refuses a row that violates it. `source: manual` sidesteps the
    // separate wholesale/website completeness constraints so this insert
    // fails on the arithmetic check specifically.
    expect(fn () => DB::connection(FLAT_TAKA_CONNECTION)->table('orders')->insert([
        'public_id' => (string) Str::ulid(), 'reference' => 'ORD-BAD-'.Str::random(8), 'source' => 'manual',
        'status' => 'payment_pending', 'business_account_id' => DB::connection(FLAT_TAKA_CONNECTION)->table('orders')->value('business_account_id'),
        'customer' => json_encode(['business_name' => 'x', 'contact_name' => 'x', 'email' => 'x@example.test', 'mobile' => '01712345678']),
        'currency_code' => 'BDT',
        'subtotal' => 100.00, 'discount' => 0, 'delivery' => 0, 'tax' => 0, 'tax_included' => 0, 'cod_fee' => 0,
        'total' => 999.99, // does not reconcile
        'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps every immutability guard armed, resolving the columns it protects by their new names', function () {
    $files = flatTakaMigrateToPreD26();
    $ids = flatTakaSeedPreD26Rows();
    flatTakaMigrate([FLAT_TAKA_MIGRATION => $files[FLAT_TAKA_MIGRATION]]);

    $orderId = $ids['order'];

    // The four guards that were BEFORE UPDATE OF <named columns> -- the ones
    // Postgres would refuse to ALTER TYPE on while the trigger still named
    // them -- refuse an ordinary update on the renamed column.
    expect(fn () => DB::connection(FLAT_TAKA_CONNECTION)->table('orders')->where('id', $orderId)->update(['total' => '1.00']))
        ->toThrow(QueryException::class);

    // ledger_entries' append-only guard was never touched by this migration
    // at all (ALTER TABLE does not fire row triggers) and stays armed
    // throughout.
    expect(fn () => DB::connection(FLAT_TAKA_CONNECTION)->table('ledger_entries')->where('id', $ids['ledger'])->update(['credit' => '1.00']))
        ->toThrow(QueryException::class);

    // No guard resolves a column that no longer exists.
    foreach ([
        ['orders', 'orders_locked_columns'],
        ['supplier_payables', 'supplier_payables_locked_columns'],
        ['supplier_withdrawals', 'supplier_withdrawals_locked_columns'],
        ['website_charges', 'website_charges_locked_columns'],
        ['referral_commissions', 'referral_commissions_locked_columns'],
        ['referral_plans', 'referral_plans_locked_columns'],
        ['referral_qualifying_events', 'referral_qualifying_events_locked_columns'],
    ] as [$table, $trigger]) {
        $def = flatTakaTriggerDefinition($table, $trigger);
        expect($def)->not->toBeNull()
            ->and($def)->not->toContain('_minor');
    }

    // The three hard-coded-body trigger functions no longer reference a
    // column that stopped existing at the rename.
    foreach ([
        'feriwala_order_item_supplier_matches_offer',
        'feriwala_supplier_payable_matches_line',
        'feriwala_supplier_payable_reversal_fits',
    ] as $function) {
        expect(flatTakaFunctionDefinition($function))->not->toContain('_minor');
    }
});

it('rolls back to bigint poisha exactly, restoring the old names, guards, and function bodies', function () {
    $files = flatTakaMigrateToPreD26();
    $ids = flatTakaSeedPreD26Rows();
    $migration = [FLAT_TAKA_MIGRATION => $files[FLAT_TAKA_MIGRATION]];

    $before = [
        'wallet_max' => flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total_minor'),
        'reversal' => flatTakaValue('wallet_transactions', 'id', $ids['reversal'], 'amount_minor'),
        'order_total' => flatTakaValue('orders', 'id', $ids['order'], 'total_minor'),
    ];

    flatTakaMigrate($migration);
    flatTakaRollBack($migration);

    expect(flatTakaColumn('wallets', 'total_minor')->data_type)->toBe('bigint')
        ->and(flatTakaColumn('wallets', 'total'))->toBeNull()
        ->and(flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total_minor'))->toBe($before['wallet_max'])
        ->and(flatTakaValue('wallet_transactions', 'id', $ids['reversal'], 'amount_minor'))->toBe($before['reversal'])
        ->and(flatTakaValue('orders', 'id', $ids['order'], 'total_minor'))->toBe($before['order_total']);

    // Guards and hard-coded functions are back to referencing the old names.
    expect(flatTakaTriggerDefinition('orders', 'orders_locked_columns'))->toContain('total_minor')
        ->and(flatTakaFunctionDefinition('feriwala_supplier_payable_reversal_fits'))->toContain('amount_minor');

    // And they still work: an update to the (restored) old column name is refused.
    expect(fn () => DB::connection(FLAT_TAKA_CONNECTION)->table('orders')->where('id', $ids['order'])->update(['total_minor' => 1]))
        ->toThrow(QueryException::class);

    expect(DB::connection(FLAT_TAKA_CONNECTION)->table('migrations')->orderByDesc('id')->value('migration'))
        ->toBe(FLAT_TAKA_PRE_MIGRATION);
});

it('refuses to roll back a value whose reversal to bigint would overflow, without changing anything', function () {
    $files = flatTakaMigrateToPreD26();
    $ids = flatTakaSeedPreD26Rows();
    $migration = [FLAT_TAKA_MIGRATION => $files[FLAT_TAKA_MIGRATION]];

    flatTakaMigrate($migration);

    // A value NUMERIC(19,2) can hold but bigint poisha never could: its
    // integer part alone already exceeds what *100 leaves room for.
    DB::connection(FLAT_TAKA_CONNECTION)->table('wallets')
        ->where('id', $ids['wallet_max'])
        ->update(['total' => '99999999999999999.99']);

    expect(fn () => flatTakaRollBack($migration))->toThrow(RuntimeException::class);

    // Nothing was touched: still converted, still numeric, guard still new-named.
    expect(flatTakaColumn('wallets', 'total')->data_type)->toBe('numeric')
        ->and(flatTakaTriggerDefinition('orders', 'orders_locked_columns'))->not->toContain('_minor');

    // Restore the value and confirm rollback now succeeds cleanly, proving
    // the refusal was about that one value, not a broken migration.
    DB::connection(FLAT_TAKA_CONNECTION)->table('wallets')
        ->where('id', $ids['wallet_max'])
        ->update(['total' => '92233720368547758.07']);

    flatTakaRollBack($migration);

    expect(flatTakaColumn('wallets', 'total_minor')->data_type)->toBe('bigint');
});

it('is safe to run twice: a second up() changes nothing on a table already converted', function () {
    $files = flatTakaMigrateToPreD26();
    $ids = flatTakaSeedPreD26Rows();
    flatTakaMigrate([FLAT_TAKA_MIGRATION => $files[FLAT_TAKA_MIGRATION]]);

    $before = flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total');

    /** @var Migrator $migrator */
    $migrator = app('migrator');
    $migrator->usingConnection(FLAT_TAKA_CONNECTION, function () {
        $instance = require database_path('migrations/2026_10_05_100000_convert_monetary_storage_to_flat_taka.php');
        $instance->up();
    });

    expect(flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total'))->toBe($before)
        ->and(flatTakaColumn('wallets', 'total')->data_type)->toBe('numeric');
});

it('is safe to roll back twice: a second down() is a no-op once already reverted', function () {
    $files = flatTakaMigrateToPreD26();
    $ids = flatTakaSeedPreD26Rows();
    $migration = [FLAT_TAKA_MIGRATION => $files[FLAT_TAKA_MIGRATION]];

    flatTakaMigrate($migration);
    flatTakaRollBack($migration);

    $before = flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total_minor');

    /** @var Migrator $migrator */
    $migrator = app('migrator');
    $migrator->usingConnection(FLAT_TAKA_CONNECTION, function () {
        $instance = require database_path('migrations/2026_10_05_100000_convert_monetary_storage_to_flat_taka.php');
        $instance->down();
    });

    expect(flatTakaValue('wallets', 'id', $ids['wallet_max'], 'total_minor'))->toBe($before)
        ->and(flatTakaColumn('wallets', 'total_minor')->data_type)->toBe('bigint');
});
