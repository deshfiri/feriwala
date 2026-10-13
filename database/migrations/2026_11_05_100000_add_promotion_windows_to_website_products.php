<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flash deals: an optional window on a selection's promotional price (P2 H6, C4).
 *
 * Additive. A selection with no window behaves exactly as before — its
 * promotion applies whenever it is set. A window bounds it: the promotion
 * applies from `promotion_starts_at` (inclusive) until `promotion_ends_at`
 * (exclusive), either end optional. Both are `TIMESTAMPTZ`, written in UTC.
 *
 * `promotion_live` is the promotion's state as last announced to the
 * storefront. The scheduled sweep compares it with the state the clock now
 * gives and announces only rows where the two differ, once each, so running
 * the sweep twice announces nothing the second time. Every existing promotion
 * has no window and is in force, so the backfill sets it to whether a
 * promotion is set at all — and the first sweep after this migration announces
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE website_products
                ADD COLUMN promotion_starts_at TIMESTAMPTZ NULL,
                ADD COLUMN promotion_ends_at TIMESTAMPTZ NULL,
                ADD COLUMN promotion_live BOOLEAN NOT NULL DEFAULT FALSE;

            UPDATE website_products SET promotion_live = (promotional_price IS NOT NULL);

            ALTER TABLE website_products
                ADD CONSTRAINT website_products_promotion_window_is_ordered CHECK (
                    promotion_starts_at IS NULL
                    OR promotion_ends_at IS NULL
                    OR promotion_ends_at > promotion_starts_at
                ),
                ADD CONSTRAINT website_products_promotion_window_needs_a_promotion CHECK (
                    (promotion_starts_at IS NULL AND promotion_ends_at IS NULL)
                    OR promotional_price IS NOT NULL
                );

            CREATE INDEX website_products_promotion_starts
                ON website_products (promotion_starts_at)
                WHERE promotion_starts_at IS NOT NULL;

            CREATE INDEX website_products_promotion_ends
                ON website_products (promotion_ends_at)
                WHERE promotion_ends_at IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        // Dropping the window would put a scheduled promotion on sale at once.
        // It refuses instead.
        if (DB::table('website_products')->whereNotNull('promotion_starts_at')->orWhereNotNull('promotion_ends_at')->exists()) {
            throw new RuntimeException('Website products have promotion windows; they cannot be rolled back without changing what customers are charged.');
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS website_products_promotion_ends;
            DROP INDEX IF EXISTS website_products_promotion_starts;

            ALTER TABLE website_products
                DROP CONSTRAINT website_products_promotion_window_needs_a_promotion,
                DROP CONSTRAINT website_products_promotion_window_is_ordered,
                DROP COLUMN promotion_live,
                DROP COLUMN promotion_ends_at,
                DROP COLUMN promotion_starts_at;
        SQL);
    }
};
