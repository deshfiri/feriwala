<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert every stored money value from integer minor units (poisha) to
 * exact flat Taka, and drop the `_minor` naming that went with it (D26).
 *
 * `10050` becomes `100.50`. `10000` becomes `100.00`. `1` becomes `0.01`.
 * `-2550` becomes `-25.50`. Every column converts by
 * `value::numeric * 0.01` inside `ALTER COLUMN ... TYPE numeric(19,2) USING
 * (...)`, which Postgres runs as a single atomic DDL statement per table —
 * there is no PHP-side arithmetic anywhere in this migration, and no float
 * or double at any stage.
 *
 * It is multiplication, not division. Postgres's numeric `/` picks its
 * result scale from a "significant digits" heuristic that is blind to what
 * the caller actually needs: for a 19-digit numerator — bigint's own
 * maximum, `9223372036854775807` — `value::numeric / 100` silently returns
 * `92233720368547758` with **zero decimal places**, discarding the `.07`
 * with no error and no warning. Confirmed by direct experiment, not
 * documentation. Numeric multiplication carries no such heuristic: its
 * result scale is always exactly `scale(a) + scale(b)`, so `value * 0.01`
 * (scale 0 + scale 2) is scale 2 for every magnitude bigint can hold,
 * verified against the same maximum value and against bcmath as ground
 * truth for a full set of poisha amounts. `down()`'s reversal already used
 * multiplication (`value * 100`) and never had this exposure.
 *
 * 125 columns across 47 tables, all `bigint`, confirmed against the live
 * migration-defined schema on 2026-09-23 (an isolated schema built by
 * running every existing migration from empty) rather than assumed from the
 * D26 decision record. Stripping the `_minor` suffix produces no name
 * collision on any of the 47 tables.
 *
 * Two mechanical facts, both verified against that schema rather than
 * assumed, govern the ordering below:
 *
 * 1. `ALTER COLUMN ... TYPE` is refused outright — "cannot alter type of a
 *    column used in a trigger definition" — on any column named in a
 *    `BEFORE UPDATE OF <columns>` trigger. Four of the seven immutability
 *    guards use that form (`orders`, `supplier_payables`,
 *    `supplier_withdrawals`, `website_charges`) and must be dropped before
 *    their tables convert. The other three (`referral_commissions`,
 *    `referral_plans`, `referral_qualifying_events`) fire on unconditional
 *    `BEFORE UPDATE` and do not block the ALTER — but every one of the
 *    seven resolves its protected columns as string literals in `TG_ARGV`
 *    (`feriwala_catalogue_columns_are_locked()`), and Postgres does not
 *    rewrite those literals on rename. A guard left pointing at
 *    `amount_minor` after the column becomes `amount` fails open silently:
 *    no error, no refusal, a financial identity column left writable. All
 *    seven are therefore dropped and recreated with the new names, even
 *    though only four are strictly required for the type change to
 *    proceed.
 *
 * 2. A multi-column CHECK constraint (`orders_total_adds_up`,
 *    `order_items_subtotal_adds_up`, `supplier_payables_gross_adds_up`, and
 *    others) is validated after *every* `ALTER COLUMN` sub-clause, not once
 *    at the end of the statement — converting `subtotal` alone while
 *    `total` is still unconverted trips the constraint mid-way, even though
 *    the final state would satisfy it. Every money column belonging to one
 *    table is therefore altered in a single `ALTER TABLE` statement with one
 *    `ALTER COLUMN` clause per column, never one statement per column.
 *
 * Three more trigger functions hard-code a `_minor` column name directly in
 * their SQL body rather than resolving it through `TG_ARGV`
 * (`feriwala_order_item_supplier_matches_offer`,
 * `feriwala_supplier_payable_matches_line`,
 * `feriwala_supplier_payable_reversal_fits`). These fire only on `INSERT`,
 * so they do not block the ALTER and do not fail silently — they would fail
 * loudly, referencing a column that no longer exists, on the very next
 * insert after the rename. Their bodies are replaced in the same
 * transaction.
 *
 * No index, generated column, or view in the schema references a `_minor`
 * column. No CHECK constraint's own *name* contains `_minor` — only some of
 * their expressions do, and those expressions follow a column rename
 * automatically (confirmed empirically), so no constraint is renamed here.
 *
 * Idempotency: every table-level conversion checks, via
 * `information_schema.columns` against `current_schema()`, whether its
 * first old column still exists before touching it. A table already
 * converted (old column gone) is skipped entirely, so a second invocation
 * of `up()` — however it happened — changes nothing on tables already done.
 *
 * Rollback: exact and lossless for every value this migration could
 * actually have produced, because `NUMERIC(19,2)` can never hold more than
 * two decimal places, so `amount * 100` is always a whole number — there is
 * no fractional part a reversal to `bigint` could lose. The one way
 * rollback could lose data is a value whose `amount * 100` no longer fits
 * `bigint` (max `9223372036854775807`), which the {@see MAX_INTEGER_DIGITS}
 * ceiling in `App\Support\Money\Money` does not itself rule out. `down()`
 * scans every converted column for that condition before changing anything
 * and refuses outright, naming the exact table and column, rather than
 * either truncating a value or leaving the schema half-reverted.
 */
return new class extends Migration
{
    /**
     * table => [old `_minor` column => new flat-Taka column].
     *
     * @var array<string, array<string, string>>
     */
    private const CONVERSIONS = [
        'cart_items' => [
            'unit_price_seen_minor' => 'unit_price_seen',
        ],
        'carts' => [
            'confirmed_total_minor' => 'confirmed_total',
        ],
        'coupon_redemptions' => [
            'amount_minor' => 'amount',
        ],
        'coupons' => [
            'minimum_spend_minor' => 'minimum_spend',
            'maximum_discount_minor' => 'maximum_discount',
        ],
        'deposit_rules' => [
            'required_initial_deposit_minor' => 'required_initial_deposit',
            'minimum_balance_minor' => 'minimum_balance',
            'required_top_up_minor' => 'required_top_up',
            'low_balance_threshold_minor' => 'low_balance_threshold',
            'critical_balance_threshold_minor' => 'critical_balance_threshold',
        ],
        'fee_rules' => [
            'amount_minor' => 'amount',
        ],
        'invoice_lines' => [
            'amount_minor' => 'amount',
        ],
        'invoices' => [
            'subtotal_minor' => 'subtotal',
            'total_minor' => 'total',
        ],
        'ledger_entries' => [
            'debit_minor' => 'debit',
            'credit_minor' => 'credit',
            'balance_before_minor' => 'balance_before',
            'balance_after_minor' => 'balance_after',
            'pending_minor' => 'pending',
            'reserved_minor' => 'reserved',
            'available_minor' => 'available',
            'hold_minor' => 'hold',
        ],
        'order_items' => [
            'unit_price_minor' => 'unit_price',
            'line_subtotal_minor' => 'line_subtotal',
            'discount_minor' => 'discount',
            'tax_minor' => 'tax',
            'tax_included_minor' => 'tax_included',
            'line_total_minor' => 'line_total',
            'supplier_rate_minor' => 'supplier_rate',
            'platform_rate_minor' => 'platform_rate',
            'platform_margin_minor' => 'platform_margin',
        ],
        'order_return_items' => [
            'refund_amount_minor' => 'refund_amount',
        ],
        'order_returns' => [
            'refund_amount_minor' => 'refund_amount',
        ],
        'orders' => [
            'subtotal_minor' => 'subtotal',
            'discount_minor' => 'discount',
            'delivery_minor' => 'delivery',
            'tax_minor' => 'tax',
            'tax_included_minor' => 'tax_included',
            'cod_fee_minor' => 'cod_fee',
            'total_minor' => 'total',
        ],
        'package_charges' => [
            'amount_minor' => 'amount',
        ],
        'packages' => [
            'fee_minor' => 'fee',
            'registration_fee_minor' => 'registration_fee',
            'renewal_fee_minor' => 'renewal_fee',
            'required_deposit_minor' => 'required_deposit',
            'minimum_balance_minor' => 'minimum_balance',
        ],
        'payment_allocations' => [
            'amount_minor' => 'amount',
        ],
        'payment_logs' => [
            'amount_minor' => 'amount',
        ],
        'payment_tax_lines' => [
            'taxable_amount_minor' => 'taxable_amount',
            'tax_amount_minor' => 'tax_amount',
        ],
        'payments' => [
            'amount_minor' => 'amount',
            'revenue_minor' => 'revenue',
            'settled_amount_minor' => 'settled_amount',
            'gateway_fee_minor' => 'gateway_fee',
        ],
        'product_price_tiers' => [
            'unit_price_minor' => 'unit_price',
        ],
        'product_variants' => [
            'wholesale_price_minor' => 'wholesale_price',
            'base_cost_minor' => 'base_cost',
        ],
        'products' => [
            'base_cost_minor' => 'base_cost',
            'wholesale_price_minor' => 'wholesale_price',
            'suggested_selling_price_minor' => 'suggested_selling_price',
            'minimum_selling_price_minor' => 'minimum_selling_price',
            'maximum_selling_price_minor' => 'maximum_selling_price',
        ],
        'referral_commissions' => [
            'commission_base_minor' => 'commission_base',
            'amount_minor' => 'amount',
        ],
        'referral_plan_levels' => [
            'amount_minor' => 'amount',
            'cap_minor' => 'cap',
        ],
        'referral_plans' => [
            'joining_reward_amount_minor' => 'joining_reward_amount',
            'joining_reward_cap_minor' => 'joining_reward_cap',
            'minimum_qualifying_payment_minor' => 'minimum_qualifying_payment',
        ],
        'referral_qualifying_events' => [
            'commission_base_minor' => 'commission_base',
        ],
        'refund_requests' => [
            'amount_minor' => 'amount',
        ],
        'supplier_ledger_entries' => [
            'debit_minor' => 'debit',
            'credit_minor' => 'credit',
            'balance_before_minor' => 'balance_before',
            'balance_after_minor' => 'balance_after',
            'reserved_before_minor' => 'reserved_before',
            'reserved_after_minor' => 'reserved_after',
            'recovery_before_minor' => 'recovery_before',
            'recovery_after_minor' => 'recovery_after',
        ],
        'supplier_offer_price_changes' => [
            'supplier_rate_minor' => 'supplier_rate',
            'platform_rate_minor' => 'platform_rate',
        ],
        'supplier_offers' => [
            'supplier_rate_minor' => 'supplier_rate',
            'platform_rate_minor' => 'platform_rate',
        ],
        'supplier_payable_reversals' => [
            'amount_minor' => 'amount',
        ],
        'supplier_payables' => [
            'supplier_rate_minor' => 'supplier_rate',
            'gross_amount_minor' => 'gross_amount',
        ],
        'supplier_product_listing_items' => [
            'supplier_rate_minor' => 'supplier_rate',
        ],
        'supplier_wallets' => [
            'total_minor' => 'total',
            'reserved_minor' => 'reserved',
            'recovery_minor' => 'recovery',
        ],
        'supplier_withdrawals' => [
            'amount_minor' => 'amount',
        ],
        'suppliers' => [
            'withdrawal_minimum_override_minor' => 'withdrawal_minimum_override',
            'withdrawal_maximum_override_minor' => 'withdrawal_maximum_override',
        ],
        'user_packages' => [
            'paid_fee_minor' => 'paid_fee',
        ],
        'wallet_deposit_obligations' => [
            'required_deposit_minor' => 'required_deposit',
            'minimum_balance_minor' => 'minimum_balance',
            'required_top_up_minor' => 'required_top_up',
            'low_balance_threshold_minor' => 'low_balance_threshold',
            'critical_balance_threshold_minor' => 'critical_balance_threshold',
        ],
        'wallet_transaction_events' => [
            'amount_minor' => 'amount',
            'bucket_before_minor' => 'bucket_before',
            'bucket_after_minor' => 'bucket_after',
            'total_before_minor' => 'total_before',
            'total_after_minor' => 'total_after',
        ],
        'wallet_transactions' => [
            'amount_minor' => 'amount',
        ],
        'wallets' => [
            'total_minor' => 'total',
            'required_deposit_minor' => 'required_deposit',
            'reserved_minor' => 'reserved',
            'pending_minor' => 'pending',
            'hold_minor' => 'hold',
            'cod_receivable_minor' => 'cod_receivable',
            'minimum_balance_minor' => 'minimum_balance',
        ],
        'website_charges' => [
            'amount_minor' => 'amount',
        ],
        'website_domains' => [
            'fee_minor' => 'fee',
        ],
        'website_hostings' => [
            'fee_minor' => 'fee',
        ],
        'website_product_price_rules' => [
            'min_price_minor' => 'min_price',
            'max_price_minor' => 'max_price',
            'suggested_price_minor' => 'suggested_price',
        ],
        'website_products' => [
            'price_minor' => 'price',
            'promotional_price_minor' => 'promotional_price',
        ],
        'websites' => [
            'setup_fee_minor' => 'setup_fee',
            'domain_fee_minor' => 'domain_fee',
            'hosting_fee_minor' => 'hosting_fee',
            'required_deposit_minor' => 'required_deposit',
            'minimum_balance_minor' => 'minimum_balance',
        ],
    ];

    /**
     * The bigint column type's maximum magnitude. A converted value whose
     * `amount * 100` would exceed this cannot be reversed exactly, so
     * down() refuses rather than truncate it.
     */
    private const BIGINT_MAX = '9223372036854775807';

    public function up(): void
    {
        $this->dropGuards();

        foreach (self::CONVERSIONS as $table => $renames) {
            $this->convertTableForward($table, $renames);
        }

        $this->replaceHardcodedFunctions(new: true);
        $this->recreateGuards(new: true);
    }

    public function down(): void
    {
        $this->assertReversible();

        $this->dropGuards();

        foreach (self::CONVERSIONS as $table => $renames) {
            $this->convertTableBackward($table, $renames);
        }

        $this->replaceHardcodedFunctions(new: false);
        $this->recreateGuards(new: false);
    }

    /**
     * @param  literal-string  $table
     * @param  array<literal-string, literal-string>  $renames  old => new
     */
    private function convertTableForward(string $table, array $renames): void
    {
        $oldColumns = array_keys($renames);

        if (! $this->columnExists($table, $oldColumns[0])) {
            // Already converted (or never created this way) — a second
            // invocation of up() must change nothing here.
            return;
        }

        $defaults = $this->currentDefaults($table, $oldColumns);

        // Multiplication by 0.01, never division by 100 -- see the class
        // docblock for why plain "/" silently drops precision at the high
        // end of bigint's range.
        /** @var literal-string $clauses */
        $clauses = implode(', ', array_map(
            /** @param literal-string $column */
            fn (string $column): string => "ALTER COLUMN {$column} TYPE numeric(19,2) USING ({$column}::numeric * 0.01)",
            $oldColumns,
        ));

        // One statement for every money column on this table: a multi-column
        // CHECK constraint is validated after each sub-clause, not once at
        // the end, so converting them one statement at a time can trip a
        // constraint that the final state would satisfy.
        DB::unprepared("ALTER TABLE {$table} {$clauses}");

        foreach ($renames as $old => $new) {
            DB::unprepared("ALTER TABLE {$table} RENAME COLUMN {$old} TO {$new}");
        }

        foreach ($renames as $old => $new) {
            $literal = $defaults[$old] ?? null;

            if ($literal === null) {
                continue;
            }

            $taka = $this->safeDecimalLiteral(bcdiv($literal, '100', 2));
            DB::unprepared("ALTER TABLE {$table} ALTER COLUMN {$new} SET DEFAULT {$taka}");
        }
    }

    /**
     * @param  literal-string  $table
     * @param  array<literal-string, literal-string>  $renames  old => new
     */
    private function convertTableBackward(string $table, array $renames): void
    {
        $newColumns = array_values($renames);

        if (! $this->columnExists($table, $newColumns[0])) {
            // Not converted (or already rolled back) — nothing to reverse.
            return;
        }

        $defaults = $this->currentDefaults($table, $newColumns);

        /** @var literal-string $clauses */
        $clauses = implode(', ', array_map(
            /** @param literal-string $column */
            fn (string $column): string => "ALTER COLUMN {$column} TYPE bigint USING ROUND({$column} * 100)::bigint",
            $newColumns,
        ));

        DB::unprepared("ALTER TABLE {$table} {$clauses}");

        foreach ($renames as $old => $new) {
            DB::unprepared("ALTER TABLE {$table} RENAME COLUMN {$new} TO {$old}");
        }

        foreach ($renames as $old => $new) {
            $literal = $defaults[$new] ?? null;

            if ($literal === null) {
                continue;
            }

            $minor = $this->safeDecimalLiteral(bcmul($literal, '100', 0));
            DB::unprepared("ALTER TABLE {$table} ALTER COLUMN {$old} SET DEFAULT {$minor}");
        }
    }

    /**
     * Refuse to roll back if any converted value can no longer fit exactly
     * back into bigint poisha — data-loss-free rollback is the only kind
     * this migration will perform.
     */
    private function assertReversible(): void
    {
        $overflowing = [];

        foreach (self::CONVERSIONS as $table => $renames) {
            foreach ($renames as $new) {
                if (! $this->columnExists($table, $new)) {
                    // Not converted on this database — nothing to check.
                    continue;
                }

                $count = DB::selectOne(
                    "SELECT count(*) AS n FROM {$table} WHERE abs({$new}) * 100 > ".self::BIGINT_MAX,
                )->n;

                if ($count > 0) {
                    $overflowing[] = "{$table}.{$new} ({$count} row(s))";
                }
            }
        }

        if ($overflowing !== []) {
            throw new RuntimeException(
                'Refusing to roll back 2026_10_05_100000_convert_monetary_storage_to_flat_taka: '.
                'the following columns hold a value whose reversal to bigint poisha would overflow '.
                'and cannot be restored exactly: '.implode(', ', $overflowing).
                '. This migration is forward-only for this data; a corrective migration is required instead.',
            );
        }
    }

    /**
     * @return array{table: literal-string, trigger: literal-string, unconditional: bool, columns_old: list<literal-string>, columns_new: list<literal-string>}[]
     */
    private function guards(): array
    {
        return [
            [
                'table' => 'orders',
                'trigger' => 'orders_locked_columns',
                'unconditional' => false,
                'columns_old' => ['public_id', 'reference', 'source', 'business_account_id', 'website_id', 'payment_id', 'idempotency_key', 'checkout_fingerprint', 'customer', 'billing_address', 'shipping_address', 'currency_code', 'subtotal_minor', 'discount_minor', 'delivery_minor', 'tax_minor', 'tax_included_minor', 'cod_fee_minor', 'total_minor', 'coupon_code', 'placed_at', 'intended_resale_channel'],
                'columns_new' => ['public_id', 'reference', 'source', 'business_account_id', 'website_id', 'payment_id', 'idempotency_key', 'checkout_fingerprint', 'customer', 'billing_address', 'shipping_address', 'currency_code', 'subtotal', 'discount', 'delivery', 'tax', 'tax_included', 'cod_fee', 'total', 'coupon_code', 'placed_at', 'intended_resale_channel'],
            ],
            [
                'table' => 'supplier_payables',
                'trigger' => 'supplier_payables_locked_columns',
                'unconditional' => false,
                'columns_old' => ['public_id', 'reference', 'supplier_id', 'order_id', 'order_item_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'quantity', 'supplier_rate_minor', 'gross_amount_minor', 'currency_code', 'triggering_event', 'idempotency_key'],
                'columns_new' => ['public_id', 'reference', 'supplier_id', 'order_id', 'order_item_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'quantity', 'supplier_rate', 'gross_amount', 'currency_code', 'triggering_event', 'idempotency_key'],
            ],
            [
                'table' => 'supplier_withdrawals',
                'trigger' => 'supplier_withdrawals_locked_columns',
                'unconditional' => false,
                'columns_old' => ['public_id', 'reference', 'supplier_id', 'supplier_wallet_id', 'supplier_payout_method_id', 'payout_snapshot', 'amount_minor', 'currency_code', 'idempotency_key'],
                'columns_new' => ['public_id', 'reference', 'supplier_id', 'supplier_wallet_id', 'supplier_payout_method_id', 'payout_snapshot', 'amount', 'currency_code', 'idempotency_key'],
            ],
            [
                'table' => 'website_charges',
                'trigger' => 'website_charges_locked_columns',
                'unconditional' => false,
                'columns_old' => ['public_id', 'website_id', 'business_account_id', 'type', 'amount_minor', 'currency_code'],
                'columns_new' => ['public_id', 'website_id', 'business_account_id', 'type', 'amount', 'currency_code'],
            ],
            [
                'table' => 'referral_commissions',
                'trigger' => 'referral_commissions_locked_columns',
                'unconditional' => true,
                'columns_old' => ['public_id', 'referral_qualifying_event_id', 'referral_plan_id', 'beneficiary_account_id', 'source_account_id', 'level', 'kind', 'rule_snapshot', 'commission_base_minor', 'amount_minor', 'currency_code', 'capped', 'skip_reason', 'available_at', 'created_at'],
                'columns_new' => ['public_id', 'referral_qualifying_event_id', 'referral_plan_id', 'beneficiary_account_id', 'source_account_id', 'level', 'kind', 'rule_snapshot', 'commission_base', 'amount', 'currency_code', 'capped', 'skip_reason', 'available_at', 'created_at'],
            ],
            [
                'table' => 'referral_plans',
                'trigger' => 'referral_plans_locked_columns',
                'unconditional' => true,
                'columns_old' => ['public_id', 'package_id', 'trigger_event', 'commission_base', 'max_depth', 'currency_code', 'joining_reward_type', 'joining_reward_amount_minor', 'joining_reward_rate_bps', 'joining_reward_cap_minor', 'holding_days', 'minimum_qualifying_payment_minor', 'qualifies_suspended', 'qualifies_restricted', 'qualifies_package_lapsed', 'qualifies_not_active', 'effective_from', 'reason', 'opened_by', 'created_at'],
                'columns_new' => ['public_id', 'package_id', 'trigger_event', 'commission_base', 'max_depth', 'currency_code', 'joining_reward_type', 'joining_reward_amount', 'joining_reward_rate_bps', 'joining_reward_cap', 'holding_days', 'minimum_qualifying_payment', 'qualifies_suspended', 'qualifies_restricted', 'qualifies_package_lapsed', 'qualifies_not_active', 'effective_from', 'reason', 'opened_by', 'created_at'],
            ],
            [
                'table' => 'referral_qualifying_events',
                'trigger' => 'referral_qualifying_events_locked_columns',
                'unconditional' => true,
                'columns_old' => ['public_id', 'trigger_event', 'source_account_id', 'subject_type', 'subject_id', 'payment_id', 'referral_plan_id', 'currency_code', 'commission_base_minor', 'chain', 'occurred_at', 'created_at'],
                'columns_new' => ['public_id', 'trigger_event', 'source_account_id', 'subject_type', 'subject_id', 'payment_id', 'referral_plan_id', 'currency_code', 'commission_base', 'chain', 'occurred_at', 'created_at'],
            ],
        ];
    }

    private function dropGuards(): void
    {
        foreach ($this->guards() as $guard) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$guard['trigger']} ON {$guard['table']}");
        }
    }

    private function recreateGuards(bool $new): void
    {
        foreach ($this->guards() as $guard) {
            $columns = $new ? $guard['columns_new'] : $guard['columns_old'];
            /** @var literal-string $bareList */
            $bareList = implode(', ', $columns);
            /** @var literal-string $quotedList */
            $quotedList = implode(', ', array_map(
                /** @param literal-string $c */
                fn (string $c): string => "'{$c}'",
                $columns,
            ));
            $ofClause = $guard['unconditional'] ? '' : "OF {$bareList} ";

            DB::unprepared("
                CREATE TRIGGER {$guard['trigger']}
                    BEFORE UPDATE {$ofClause}ON {$guard['table']}
                    FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$quotedList})
            ");
        }
    }

    /**
     * Three trigger functions hard-code a `_minor` column name in their SQL
     * body rather than resolving it through `TG_ARGV`. `CREATE OR REPLACE`
     * is idempotent by construction, so this needs no existence guard.
     */
    private function replaceHardcodedFunctions(bool $new): void
    {
        $supplierRate = $new ? 'supplier_rate' : 'supplier_rate_minor';
        $platformRate = $new ? 'platform_rate' : 'platform_rate_minor';
        $grossAmount = $new ? 'gross_amount' : 'gross_amount_minor';
        $amount = $new ? 'amount' : 'amount_minor';
        $reversedAmountType = $new ? 'numeric(19,2)' : 'bigint';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION feriwala_order_item_supplier_matches_offer() RETURNS trigger AS \$fn\$
                BEGIN
                    IF NEW.supplier_offer_id IS NULL THEN
                        RETURN NEW;
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM supplier_offers
                        WHERE id = NEW.supplier_offer_id
                          AND supplier_id = NEW.supplier_id
                          AND product_id = NEW.product_id
                          AND product_variant_id IS NOT DISTINCT FROM NEW.product_variant_id
                    ) THEN
                        RAISE EXCEPTION 'an order line''s Supplier offer must be that Supplier''s offer for the line''s own product and variation'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM supplier_offer_price_changes
                        WHERE id = NEW.supplier_offer_price_change_id
                          AND supplier_offer_id = NEW.supplier_offer_id
                          AND {$supplierRate} = NEW.{$supplierRate}
                          AND {$platformRate} = NEW.{$platformRate}
                    ) THEN
                        RAISE EXCEPTION 'an order line''s rates must be the ones recorded in its price version'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            \$fn\$ LANGUAGE plpgsql
        SQL);

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_matches_line() RETURNS trigger AS \$fn\$
                BEGIN
                    IF NOT EXISTS (
                        SELECT 1 FROM order_items
                        WHERE id = NEW.order_item_id
                          AND order_id = NEW.order_id
                          AND supplier_id = NEW.supplier_id
                          AND supplier_offer_id = NEW.supplier_offer_id
                          AND supplier_offer_price_change_id = NEW.supplier_offer_price_change_id
                          AND supplier_allocated_quantity = NEW.quantity
                          AND {$supplierRate} = NEW.{$supplierRate}
                          AND supplier_currency_code = NEW.currency_code
                    ) THEN
                        RAISE EXCEPTION 'a Supplier payable must match the allocation snapshot of its order line'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            \$fn\$ LANGUAGE plpgsql
        SQL);

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_reversal_fits() RETURNS trigger AS \$fn\$
                DECLARE
                    payable supplier_payables%ROWTYPE;
                    reversed_quantity bigint;
                    reversed_amount {$reversedAmountType};
                BEGIN
                    SELECT * INTO payable FROM supplier_payables WHERE id = NEW.supplier_payable_id FOR UPDATE;

                    IF payable.currency_code <> NEW.currency_code THEN
                        RAISE EXCEPTION 'a reversal is in the currency of its payable'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    IF NEW.{$amount} <> NEW.quantity * payable.{$supplierRate} THEN
                        RAISE EXCEPTION 'a reversal is exactly its quantity at the payable''s Supplier Rate'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    SELECT COALESCE(SUM(quantity), 0), COALESCE(SUM({$amount}), 0)
                        INTO reversed_quantity, reversed_amount
                        FROM supplier_payable_reversals WHERE supplier_payable_id = NEW.supplier_payable_id;

                    IF reversed_quantity + NEW.quantity > payable.quantity
                       OR reversed_amount + NEW.{$amount} > payable.{$grossAmount} THEN
                        RAISE EXCEPTION 'reversals cannot exceed the original Supplier payable'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN NEW;
                END;
            \$fn\$ LANGUAGE plpgsql
        SQL);
    }

    /**
     * Asserts a computed decimal string is safe to interpolate into raw SQL
     * before it is used that way, rather than merely declaring it so.
     * `bcdiv()`/`bcmul()` can only ever produce this shape, so the check
     * never actually fails -- it exists to make that safety a runtime fact,
     * not a docblock claim.
     *
     * PHPStan's `literal-string` models where a value came from (a source
     * literal), not whether it is safe to interpolate -- a regex cannot
     * change that provenance, so no `@var` override here would be honest.
     * The ignore is scoped to this one line for exactly that reason: the
     * runtime check above is the actual guarantee, not a workaround for it.
     *
     * @return literal-string
     */
    private function safeDecimalLiteral(string $value): string
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new RuntimeException("[{$value}] is not a safe decimal literal to interpolate into SQL.");
        }

        // @phpstan-ignore-next-line return.type (see docblock above)
        return $value;
    }

    private function columnExists(string $table, string $column): bool
    {
        return DB::selectOne(
            'SELECT 1 AS found FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column],
        ) !== null;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, numeric-string> column => the bare numeric literal in its DEFAULT expression, for columns that have one
     */
    private function currentDefaults(string $table, array $columns): array
    {
        $rows = DB::select(
            'SELECT column_name, column_default FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = ? AND column_name = ANY(?)',
            [$table, '{'.implode(',', $columns).'}'],
        );

        $literals = [];

        foreach ($rows as $row) {
            if ($row->column_default === null) {
                continue;
            }

            // A default in this schema is either an integer cast --
            // "'0'::bigint" -- or, once a column has already gone through
            // this migration's forward direction, a bare decimal literal
            // Postgres stores with no cast suffix at all -- "0.00". Both
            // forms are matched; the cast suffix is optional. Anything else
            // is left alone rather than guessed at.
            if (preg_match("/^'?(-?\d+(?:\.\d+)?)'?(?:::(?:bigint|numeric(?:\(\d+,\d+\))?))?$/", $row->column_default, $m) === 1 && is_numeric($m[1])) {
                $literals[$row->column_name] = $m[1];
            }
        }

        return $literals;
    }
};
