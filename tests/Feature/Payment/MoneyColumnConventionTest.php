<?php

use Illuminate\Support\Facades\DB;

/*
 * Every table that holds money says which money it is (P2-36, D4).
 *
 * A schema test rather than a behaviour one, and deliberately: the rule it
 * protects is one nobody breaks on purpose. Somebody adds a table with an
 * `amount_minor` column, forgets the currency beside it, and for as long as the
 * platform is BDT-only nothing goes wrong — until the day it is not, and there
 * is no way to tell what the old rows meant.
 *
 * Catching that at the moment the table is written costs nothing. Catching it
 * later costs a migration nobody can write correctly.
 */

/**
 * Tables holding a money column but no currency of their own, and why.
 *
 * @var array<string, string>
 */
const MONEY_COLUMN_EXEMPTIONS = [
    /*
     * A line's currency is its parent's. Splitting a payment across currencies
     * is not a thing this platform does — an allocation is a component of one
     * charge — so a column here would be a second answer that could disagree.
     */
];

function moneyTables(): array
{
    /** @var array<int, object{table_name: string, has_currency: bool}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT c.relname AS table_name,
               EXISTS (
                   SELECT 1 FROM information_schema.columns col
                   WHERE col.table_name = c.relname
                     AND col.table_schema = current_schema()
                     AND col.column_name = 'currency_code'
               ) AS has_currency
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = current_schema()
          AND c.relkind = 'r'
          AND EXISTS (
              SELECT 1 FROM information_schema.columns col
              WHERE col.table_name = c.relname
                AND col.table_schema = current_schema()
                AND col.column_name LIKE '%_minor'
          )
        ORDER BY 1
    SQL);

    return $rows;
}

it('gives every table holding an amount a currency beside it', function () {
    $missing = [];

    foreach (moneyTables() as $table) {
        if ($table->has_currency) {
            continue;
        }

        if (array_key_exists($table->table_name, MONEY_COLUMN_EXEMPTIONS)) {
            continue;
        }

        $missing[] = $table->table_name;
    }

    expect($missing)->toBe([], implode(', ', $missing).' hold money without saying which currency it is (D4).');
});

it('finds the money tables it is supposed to be checking', function () {
    // A guard on the guard: a query that silently matched nothing would pass
    // the test above for ever while checking nothing at all.
    $names = array_map(fn (object $table) => $table->table_name, moneyTables());

    expect($names)->toContain('payments')
        ->toContain('wallets')
        ->toContain('ledger_entries')
        ->and(count($names))->toBeGreaterThan(10);
});

it('defaults every one of them to the base currency', function () {
    /*
     * Version 1 operates in BDT (D4). The default is what makes that true
     * without every insert in the system having to remember it — and the
     * columns are NOT NULL, so nothing can quietly hold an amount with no
     * currency at all.
     */
    /** @var array<int, object{table_name: string, column_default: string|null, is_nullable: string}> $columns */
    $columns = DB::select(<<<'SQL'
        SELECT table_name, column_default, is_nullable
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND column_name = 'currency_code'
        ORDER BY 1
    SQL);

    $wrong = [];

    foreach ($columns as $column) {
        /*
         * `payment_logs` is the one exception, and on purpose: the most
         * interesting rows in it are notifications carrying no amount at all,
         * and defaulting those to BDT would invent a currency for a message
         * that never mentioned money.
         */
        if ($column->table_name === 'payment_logs') {
            expect($column->is_nullable)->toBe('YES');

            continue;
        }

        if ($column->is_nullable !== 'NO' || ! str_contains((string) $column->column_default, 'BDT')) {
            $wrong[] = $column->table_name;
        }
    }

    expect($wrong)->toBe([], implode(', ', $wrong).' do not default to the base currency (D4).');
});
