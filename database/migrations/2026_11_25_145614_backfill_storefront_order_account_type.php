<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Storefront orders were never given the account's type when they were placed,
 * so every one of them holds the column default, `conditional` — including the
 * orders of Non-Conditional Partners.
 *
 * Corrected only where a frozen placement-time fact proves the real type: the
 * `account_type_at_placement` snapshot on the order's platform receivable (D36).
 * An order without one keeps what it has. The account's type today is never
 * consulted — it may have changed since the order was placed.
 *
 * `orders.account_type` is locked against change by its own trigger; it is
 * lifted for this one statement, inside this migration's transaction, and put
 * back before the transaction ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $pdo = DB::connection()->getPdo();

            $pdo->exec('ALTER TABLE orders DISABLE TRIGGER orders_account_type_locked');

            $pdo->exec(<<<'SQL'
                UPDATE orders o
                   SET account_type = r.account_type_at_placement
                  FROM order_platform_receivables r
                 WHERE r.order_id = o.id
                   AND o.source = 'website'
                   AND r.account_type_at_placement IN ('conditional', 'non_conditional')
                   AND o.account_type <> r.account_type_at_placement
            SQL);

            $pdo->exec('ALTER TABLE orders ENABLE TRIGGER orders_account_type_locked');
        });
    }

    /**
     * Nothing to undo: the corrected value is the frozen fact, and the wrong
     * one it replaced cannot be told apart from a right one afterwards.
     */
    public function down(): void {}
};
