<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Models\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Upgrading carts from the P4-8 schema to `currency_code`, and back (D4, P4-8).
 *
 * P4-8 kept a confirmation's currency in a nullable `confirmed_currency_code`;
 * `2026_09_26_130000_give_carts_a_currency_code` replaces it with the cart's own
 * `currency_code`, NOT NULL and defaulting to BDT. Every other test only ever
 * sees that migration run against an empty `carts` table. This one builds the
 * exact P4-8 schema in a throwaway Postgres schema of its own, puts carts in it
 * as P4-8 wrote them, and runs the migration up and down over real rows.
 *
 * The schema is created on a connection of its own, so nothing here runs inside
 * the test transaction or touches the test schema, and it is dropped afterwards.
 */

const CART_CURRENCY_CONNECTION = 'cart_currency_migration';
const CART_CURRENCY_P4_8_MIGRATION = '2026_09_26_120000_add_checkout_confirmation_to_carts';
const CART_CURRENCY_MIGRATION = '2026_09_26_130000_give_carts_a_currency_code';

beforeEach(function () {
    $this->schema = 'cart_currency_'.Str::lower(Str::random(12));

    config()->set('database.connections.'.CART_CURRENCY_CONNECTION, [
        ...config('database.connections.'.config('database.default')),
        'search_path' => $this->schema,
    ]);

    DB::purge(CART_CURRENCY_CONNECTION);
    DB::connection(CART_CURRENCY_CONNECTION)->statement('CREATE SCHEMA "'.$this->schema.'"');
});

afterEach(function () {
    DB::connection(CART_CURRENCY_CONNECTION)->statement('DROP SCHEMA IF EXISTS "'.$this->schema.'" CASCADE');
    DB::purge(CART_CURRENCY_CONNECTION);
});

/**
 * Every migration the application runs, by name.
 *
 * @return array<string, string>
 */
function cartCurrencyMigrationFiles(): array
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    return $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
}

/**
 * Run migrations into the throwaway schema, as one batch.
 *
 * @param  array<string, string>  $files
 */
function cartCurrencyMigrate(array $files): void
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $migrator->usingConnection(CART_CURRENCY_CONNECTION, function () use ($migrator, $files) {
        if (! $migrator->repositoryExists()) {
            $migrator->getRepository()->createRepository();
        }

        $migrator->run(array_values($files));
    });
}

/**
 * Roll the throwaway schema's last batch back.
 *
 * @param  array<string, string>  $files
 */
function cartCurrencyRollBack(array $files): void
{
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $migrator->usingConnection(CART_CURRENCY_CONNECTION, fn () => $migrator->rollback(array_values($files)));
}

/**
 * Bring the throwaway schema to exactly where P4-8 left it.
 *
 * @return array<string, string> every migration, by name
 */
function cartCurrencyMigrateToP48(): array
{
    $files = cartCurrencyMigrationFiles();

    // The P4-8 schema is every migration up to and including P4-8's own; the one
    // under test must be the only migration after it, or this is not that schema.
    $after = array_keys(array_filter($files, fn (string $name) => strcmp($name, CART_CURRENCY_P4_8_MIGRATION) > 0, ARRAY_FILTER_USE_KEY));
    expect($after)->toBe([CART_CURRENCY_MIGRATION]);

    cartCurrencyMigrate(array_filter($files, fn (string $name) => strcmp($name, CART_CURRENCY_P4_8_MIGRATION) <= 0, ARRAY_FILTER_USE_KEY));

    expect(cartCurrencyColumn('confirmed_currency_code'))->not->toBeNull()
        ->and(cartCurrencyColumn('currency_code'))->toBeNull();

    return $files;
}

/**
 * Three carts written as the P4-8 schema holds them: confirmed in BDT, confirmed
 * in another currency, and not confirmed.
 *
 * @return array{confirmed_bdt: int, confirmed_usd: int, unconfirmed: int}
 */
function cartCurrencyP48Carts(): array
{
    // The account and people behind the carts are real rows in the throwaway
    // schema, made by the ordinary factories pointed at its connection.
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection(CART_CURRENCY_CONNECTION);

    try {
        $account = BusinessAccount::factory()->create();
        $others = User::factory()->count(2)->create();
    } finally {
        DB::setDefaultConnection($previous);
    }

    $confirmed = fn (string $currency) => [
        'payment_method' => 'sslcommerz',
        'confirmed_at' => now(),
        'confirmed_fingerprint' => str_repeat('f', 64),
        'confirmed_total_minor' => 2000000,
        'confirmed_currency_code' => $currency,
    ];

    $cart = fn (int $userId, array $confirmation) => (int) DB::connection(CART_CURRENCY_CONNECTION)->table('carts')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'user_id' => $userId,
        'business_account_id' => $account->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$confirmation,
    ]);

    return [
        'confirmed_bdt' => $cart($account->owner_id, $confirmed('BDT')),
        'confirmed_usd' => $cart($others[0]->id, $confirmed('USD')),
        'unconfirmed' => $cart($others[1]->id, []),
    ];
}

/**
 * @return object{is_nullable: string, column_default: string|null}|null
 */
function cartCurrencyColumn(string $column): ?object
{
    return DB::connection(CART_CURRENCY_CONNECTION)->selectOne(
        'SELECT is_nullable, column_default FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
        ['carts', $column],
    );
}

function cartCurrencyValue(int $cartId, string $column): ?string
{
    $value = DB::connection(CART_CURRENCY_CONNECTION)->table('carts')->where('id', $cartId)->value($column);

    return $value === null ? null : (string) $value;
}

/**
 * @return object{convalidated: bool, definition: string}
 */
function cartCurrencyConfirmationConstraint(): object
{
    $constraint = DB::connection(CART_CURRENCY_CONNECTION)->selectOne(
        'SELECT convalidated, pg_get_constraintdef(oid) AS definition FROM pg_constraint
         WHERE conrelid = ?::regclass AND conname = ?',
        ['carts', 'carts_confirmation_complete'],
    );

    expect($constraint)->not->toBeNull();

    // Every row already in the table satisfies it, or this refuses.
    DB::connection(CART_CURRENCY_CONNECTION)->statement('ALTER TABLE carts VALIDATE CONSTRAINT carts_confirmation_complete');

    return $constraint;
}

it('carries each confirmed cart\'s own currency forward and gives every other cart BDT', function () {
    $files = cartCurrencyMigrateToP48();
    $carts = cartCurrencyP48Carts();

    cartCurrencyMigrate([CART_CURRENCY_MIGRATION => $files[CART_CURRENCY_MIGRATION]]);

    $column = cartCurrencyColumn('currency_code');

    expect($column)->not->toBeNull()
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toContain('BDT')
        ->and(cartCurrencyColumn('confirmed_currency_code'))->toBeNull();

    expect(cartCurrencyValue($carts['confirmed_bdt'], 'currency_code'))->toBe('BDT')
        // Never converted to the default: a confirmation keeps the currency it was recorded in.
        ->and(cartCurrencyValue($carts['confirmed_usd'], 'currency_code'))->toBe('USD')
        ->and(cartCurrencyValue($carts['unconfirmed'], 'currency_code'))->toBe('BDT');

    $constraint = cartCurrencyConfirmationConstraint();

    expect($constraint->convalidated)->toBeTrue()
        ->and($constraint->definition)->toContain('confirmed_total_minor')
        ->and($constraint->definition)->not->toContain('confirmed_currency_code');

    // Still recorded all together or not at all.
    expect(fn () => DB::connection(CART_CURRENCY_CONNECTION)->table('carts')
        ->where('id', $carts['unconfirmed'])
        ->update(['confirmed_at' => now()]))
        ->toThrow(QueryException::class);
});

it('rolls back to confirmed_currency_code, keeping every confirmed cart\'s currency', function () {
    $files = cartCurrencyMigrateToP48();
    $carts = cartCurrencyP48Carts();
    $migration = [CART_CURRENCY_MIGRATION => $files[CART_CURRENCY_MIGRATION]];

    cartCurrencyMigrate($migration);

    /*
     * The expected rollback limitation, stated rather than hidden: the P4-8
     * schema has nowhere to keep the currency of a cart that is not confirmed —
     * `confirmed_currency_code` exists only for a confirmation, and its CHECK
     * demands NULL otherwise. So a currency on an unconfirmed cart (here set to
     * USD after the upgrade, as a withdrawn confirmation leaves it) cannot
     * survive rolling back. That is the old schema's shape, not data lost going
     * forward: the upgrade keeps every currency it is given.
     */
    DB::connection(CART_CURRENCY_CONNECTION)->table('carts')->where('id', $carts['unconfirmed'])->update(['currency_code' => 'USD']);

    cartCurrencyRollBack($migration);

    $restored = cartCurrencyColumn('confirmed_currency_code');

    expect($restored)->not->toBeNull()
        ->and($restored->is_nullable)->toBe('YES')
        ->and(cartCurrencyColumn('currency_code'))->toBeNull();

    expect(cartCurrencyValue($carts['confirmed_bdt'], 'confirmed_currency_code'))->toBe('BDT')
        ->and(cartCurrencyValue($carts['confirmed_usd'], 'confirmed_currency_code'))->toBe('USD')
        ->and(cartCurrencyValue($carts['unconfirmed'], 'confirmed_currency_code'))->toBeNull();

    $constraint = cartCurrencyConfirmationConstraint();

    expect($constraint->convalidated)->toBeTrue()
        ->and($constraint->definition)->toContain('confirmed_currency_code');

    // The original all-or-nothing rule is back: a confirmation without its currency is refused.
    expect(fn () => DB::connection(CART_CURRENCY_CONNECTION)->table('carts')
        ->where('id', $carts['confirmed_bdt'])
        ->update(['confirmed_currency_code' => null]))
        ->toThrow(QueryException::class);

    expect(DB::connection(CART_CURRENCY_CONNECTION)->table('migrations')->orderByDesc('id')->value('migration'))
        ->toBe(CART_CURRENCY_P4_8_MIGRATION);
});
