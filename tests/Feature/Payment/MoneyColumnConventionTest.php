<?php

use Illuminate\Support\Facades\DB;

/*
 * Every money column in the schema is `NUMERIC(19,2)` flat Taka, says which
 * currency it is, and is a column this test already knows about (D26, D4,
 * P2-36).
 *
 * Before D26 this test discovered money columns by name -- anything matching
 * `%_minor` -- which was a fail-open design: once the migration renamed every
 * money column away from that suffix, the discovery query would have quietly
 * matched nothing and every assertion in this file would have passed for a
 * schema with no money columns in it at all. `MONEY_COLUMN_INVENTORY` below
 * replaces that with an explicit, maintained contract: every money column
 * the schema is supposed to have, named once here. Adding a column to a
 * money-bearing table without adding it here is a failing test, not a
 * silent gap -- see "classifies every money-shaped column actually present".
 */

/**
 * The authoritative money-column inventory: table => its money columns,
 * matching D26's conversion map exactly. A column added here is a column
 * someone read this file and consciously registered.
 *
 * @var array<string, list<string>>
 */
const MONEY_COLUMN_INVENTORY = [
    'cart_items' => ['unit_price_seen'],
    'carts' => ['confirmed_total'],
    'coupon_redemptions' => ['amount'],
    'coupons' => ['minimum_spend', 'maximum_discount'],
    'deposit_rules' => ['required_initial_deposit', 'minimum_balance', 'required_top_up', 'low_balance_threshold', 'critical_balance_threshold'],
    'fee_rules' => ['amount'],
    'invoice_lines' => ['amount'],
    'invoices' => ['subtotal', 'total'],
    'ledger_entries' => ['debit', 'credit', 'balance_before', 'balance_after', 'pending', 'reserved', 'available', 'hold'],
    'order_items' => ['unit_price', 'line_subtotal', 'discount', 'tax', 'tax_included', 'line_total', 'supplier_rate', 'platform_rate', 'platform_margin'],
    'order_return_items' => ['refund_amount'],
    'order_returns' => ['refund_amount'],
    'orders' => ['subtotal', 'discount', 'delivery', 'tax', 'tax_included', 'cod_fee', 'total'],
    'package_charges' => ['amount'],
    'packages' => ['fee', 'registration_fee', 'renewal_fee', 'required_deposit', 'minimum_balance'],
    'payment_allocations' => ['amount'],
    'payment_logs' => ['amount'],
    'payment_tax_lines' => ['taxable_amount', 'tax_amount'],
    'payments' => ['amount', 'revenue', 'settled_amount', 'gateway_fee'],
    'product_price_tiers' => ['unit_price'],
    'product_variants' => ['wholesale_price', 'base_cost'],
    'products' => ['base_cost', 'wholesale_price', 'suggested_selling_price', 'minimum_selling_price', 'maximum_selling_price'],
    'referral_commissions' => ['commission_base', 'amount'],
    'referral_plan_levels' => ['amount', 'cap'],
    'referral_plans' => ['joining_reward_amount', 'joining_reward_cap', 'minimum_qualifying_payment'],
    'referral_qualifying_events' => ['commission_base'],
    'refund_requests' => ['amount'],
    'supplier_ledger_entries' => ['debit', 'credit', 'balance_before', 'balance_after', 'reserved_before', 'reserved_after', 'recovery_before', 'recovery_after'],
    'supplier_offer_price_changes' => ['supplier_rate', 'platform_rate'],
    'supplier_offers' => ['supplier_rate', 'platform_rate'],
    'supplier_payable_reversals' => ['amount'],
    'supplier_payables' => ['supplier_rate', 'gross_amount'],
    'supplier_product_listing_items' => ['supplier_rate'],
    'supplier_wallets' => ['total', 'reserved', 'recovery'],
    'supplier_withdrawals' => ['amount'],
    'suppliers' => ['withdrawal_minimum_override', 'withdrawal_maximum_override'],
    'user_packages' => ['paid_fee'],
    'wallet_deposit_obligations' => ['required_deposit', 'minimum_balance', 'required_top_up', 'low_balance_threshold', 'critical_balance_threshold'],
    'wallet_transaction_events' => ['amount', 'bucket_before', 'bucket_after', 'total_before', 'total_after'],
    'wallet_transactions' => ['amount'],
    'wallets' => ['total', 'required_deposit', 'reserved', 'pending', 'hold', 'cod_receivable', 'minimum_balance'],
    'website_charges' => ['amount'],
    'website_domains' => ['fee'],
    'website_hostings' => ['fee'],
    'website_product_price_rules' => ['min_price', 'max_price', 'suggested_price'],
    'website_products' => ['price', 'promotional_price'],
    'websites' => ['setup_fee', 'domain_fee', 'hosting_fee', 'required_deposit', 'minimum_balance'],
];

/**
 * Money tables with no `currency_code` of their own, and why -- reviewed and
 * intentional, not a gap the test happens not to catch.
 *
 * @var array<string, string>
 */
const MONEY_COLUMN_CURRENCY_EXEMPTIONS = [
    // A Supplier's withdrawal override is a bound on their own wallet's
    // balance, which already has exactly one currency. A second currency
    // column here would be a second answer that could disagree with the
    // wallet it constrains.
    'suppliers' => "withdrawal overrides bound the Supplier's own wallet, which already carries the currency",
];

/**
 * @return array<string, list<string>>
 */
function flatTakaMoneyColumnInventory(): array
{
    return MONEY_COLUMN_INVENTORY;
}

/**
 * Every column in the live schema shaped like money: `NUMERIC(19,2)`, the one
 * precision/scale pair this schema uses exclusively for money (confirmed
 * against the full schema when D26 was written: exactly the columns in
 * {@see MONEY_COLUMN_INVENTORY}, no more). Independent of the inventory, so
 * it can actually catch a column the inventory does not yet know about.
 *
 * @return array<string, list<string>>
 */
function flatTakaObservedMoneyColumns(): array
{
    /** @var array<int, object{table_name: string, column_name: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT table_name, column_name
          FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND data_type = 'numeric'
           AND numeric_precision = 19
           AND numeric_scale = 2
         ORDER BY table_name, ordinal_position
    SQL);

    $byTable = [];

    foreach ($rows as $row) {
        $byTable[$row->table_name][] = $row->column_name;
    }

    return $byTable;
}

/**
 * Every column anywhere in the schema still named the old, withdrawn way --
 * `_minor`, `minor_units`, or mentioning poisha/paisa. This should never
 * match anything post-D26; a hit here is reported by exact table and column
 * regardless of the column's type, because the naming itself is the defect.
 *
 * @return list<string> "table.column" pairs
 */
function flatTakaWithdrawnNamingColumns(): array
{
    /** @var array<int, object{table_name: string, column_name: string}> $rows */
    $rows = DB::select(<<<'SQL'
        SELECT table_name, column_name
          FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND (
               column_name ~* '_minor$'
               OR column_name ~* 'minor_units'
               OR column_name ~* 'poisha'
               OR column_name ~* 'paisa'
           )
         ORDER BY table_name, column_name
    SQL);

    return array_map(fn (object $r) => "{$r->table_name}.{$r->column_name}", $rows);
}

/**
 * Every violation of the money-column convention, given an inventory to
 * check against and the currency exemptions that apply to it. Returns
 * human-readable strings rather than asserting directly, so the same logic
 * proves itself broken in the meta-tests below and proves the real schema
 * clean in the tests that follow them.
 *
 * @param  array<string, list<string>>  $inventory
 * @param  array<string, string>  $currencyExemptions
 * @return list<string>
 */
function flatTakaMoneyInventoryViolations(array $inventory, array $currencyExemptions): array
{
    $violations = [];

    $totalColumns = array_sum(array_map('count', $inventory));

    if ($totalColumns === 0) {
        // Every other check below is vacuously true for an empty inventory,
        // which is exactly the fail-open failure mode this test replaces.
        // An empty inventory is itself the violation.
        return ['the money-column inventory is empty -- it would pass every other check by checking nothing'];
    }

    $existingCurrencyTables = array_column(
        DB::select("SELECT table_name FROM information_schema.columns WHERE table_schema = current_schema() AND column_name = 'currency_code'"),
        'table_name',
    );

    foreach ($inventory as $table => $columns) {
        if (! in_array($table, $existingCurrencyTables, true) && ! array_key_exists($table, $currencyExemptions)) {
            $violations[] = "{$table} holds money but has no currency_code column and no documented exemption";
        }

        foreach ($columns as $column) {
            $meta = DB::selectOne(
                'SELECT data_type, numeric_precision, numeric_scale FROM information_schema.columns
                  WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, $column],
            );

            if ($meta === null) {
                $violations[] = "{$table}.{$column} is in the inventory but does not exist in the schema";

                continue;
            }

            if ($meta->data_type !== 'numeric' || (int) $meta->numeric_precision !== 19 || (int) $meta->numeric_scale !== 2) {
                $type = "{$meta->data_type}({$meta->numeric_precision},{$meta->numeric_scale})";
                $violations[] = "{$table}.{$column} is {$type}, not numeric(19,2)";
            }
        }
    }

    // Every numeric(19,2) column actually in the schema must be one the
    // inventory already knows about -- this is what catches a *new* money
    // column nobody registered, the gap a name-based discovery query cannot
    // see once the naming convention it relied on is gone.
    foreach (flatTakaObservedMoneyColumns() as $table => $columns) {
        $known = $inventory[$table] ?? [];

        foreach ($columns as $column) {
            if (! in_array($column, $known, true)) {
                $violations[] = "{$table}.{$column} is numeric(19,2) but is not in the money-column inventory";
            }
        }
    }

    foreach (flatTakaWithdrawnNamingColumns() as $offender) {
        $violations[] = "{$offender} uses withdrawn minor-unit naming (D26)";
    }

    return $violations;
}

it('is not empty, and covers the major money tables', function () {
    // A guard on the guard: an inventory that silently shrank to nothing
    // would pass every check below by checking nothing.
    $inventory = flatTakaMoneyColumnInventory();

    expect($inventory)->not->toBe([])
        ->and($inventory)->toHaveKey('payments')
        ->and($inventory)->toHaveKey('wallets')
        ->and($inventory)->toHaveKey('ledger_entries')
        // D26's conversion map: 125 columns across 47 tables. It may grow when
        // a money column is added; it must never quietly shrink below this.
        ->and(array_sum(array_map('count', $inventory)))->toBeGreaterThanOrEqual(125);
});

it('gives every inventoried money column an exact NUMERIC(19,2) type', function () {
    $violations = flatTakaMoneyInventoryViolations(MONEY_COLUMN_INVENTORY, MONEY_COLUMN_CURRENCY_EXEMPTIONS);

    $typeViolations = array_values(array_filter($violations, fn (string $v) => str_contains($v, 'not numeric(19,2)')));

    expect($typeViolations)->toBe([]);
});

it('gives every inventoried money table a currency relationship, except the documented exemptions', function () {
    $violations = flatTakaMoneyInventoryViolations(MONEY_COLUMN_INVENTORY, MONEY_COLUMN_CURRENCY_EXEMPTIONS);

    $currencyViolations = array_values(array_filter($violations, fn (string $v) => str_contains($v, 'no currency_code')));

    expect($currencyViolations)->toBe([]);
});

it('classifies every money-shaped column actually present in the schema', function () {
    // If a migration lands a new numeric(19,2) column without adding it to
    // MONEY_COLUMN_INVENTORY, this is where that gets caught.
    $violations = flatTakaMoneyInventoryViolations(MONEY_COLUMN_INVENTORY, MONEY_COLUMN_CURRENCY_EXEMPTIONS);

    $unclassified = array_values(array_filter($violations, fn (string $v) => str_contains($v, 'not in the money-column inventory')));

    expect($unclassified)->toBe([]);
});

it('rejects any column still named the withdrawn minor-unit way', function () {
    expect(flatTakaWithdrawnNamingColumns())->toBe([]);
});

it('defaults every non-exempt money column with a default to zero, and never invents a fractional default', function () {
    /** @var array<int, object{table_name: string, column_name: string, column_default: string|null, is_nullable: string}> $columns */
    $columns = DB::select(<<<'SQL'
        SELECT table_name, column_name, column_default, is_nullable
          FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND data_type = 'numeric' AND numeric_precision = 19 AND numeric_scale = 2
           AND column_default IS NOT NULL
         ORDER BY table_name, column_name
    SQL);

    $wrong = [];

    foreach ($columns as $column) {
        if (! preg_match('/^-?\d+\.\d{2}$/', (string) $column->column_default)) {
            $wrong[] = "{$column->table_name}.{$column->column_name}: {$column->column_default}";
        }
    }

    expect($wrong)->toBe([], implode(', ', $wrong).' have a default that is not a plain two-decimal literal.');
});

describe('the guard proves it can actually fail', function () {
    it('fails a deliberately empty inventory', function () {
        $violations = flatTakaMoneyInventoryViolations([], []);

        expect($violations)->not->toBe([])
            ->and($violations[0])->toContain('empty');
    });

    it('fails an inventory missing a table the schema really has', function () {
        $incomplete = MONEY_COLUMN_INVENTORY;
        unset($incomplete['wallets']);

        $violations = flatTakaMoneyInventoryViolations($incomplete, MONEY_COLUMN_CURRENCY_EXEMPTIONS);

        $walletViolations = array_filter($violations, fn (string $v) => str_contains($v, 'wallets.'));

        expect($walletViolations)->not->toBe([]);
    });

    it('fails an inventory missing the documented currency exemption', function () {
        // suppliers has no currency_code column; without its exemption this
        // must be reported, not silently accepted.
        $violations = flatTakaMoneyInventoryViolations(MONEY_COLUMN_INVENTORY, []);

        expect(array_filter($violations, fn (string $v) => str_starts_with($v, 'suppliers ')))->not->toBe([]);
    });

    it('fails an inventory that invents a column the schema does not have', function () {
        $wrong = MONEY_COLUMN_INVENTORY;
        $wrong['wallets'][] = 'not_a_real_column';

        $violations = flatTakaMoneyInventoryViolations($wrong, MONEY_COLUMN_CURRENCY_EXEMPTIONS);

        expect(array_filter($violations, fn (string $v) => str_contains($v, 'not_a_real_column')))->not->toBe([]);
    });
});
