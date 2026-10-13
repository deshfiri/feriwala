<?php

use App\Domain\Website\Actions\RefreshWebsiteBestSellers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Best sellers: each selection's sales over the last 30 days (P2 C3, D31 H5).
 *
 * A **derived** table, rebuilt in full by the scheduled refresh
 * ({@see RefreshWebsiteBestSellers}) — never
 * edited, never a source of truth. One row per selection that sold at least
 * one net unit; a selection with no row sold nothing and ranks last. The
 * storefront's `sort=best_selling` reads it by primary key, so no catalogue
 * request aggregates orders.
 *
 * Two supporting indexes keep the refresh bounded by the window rather than by
 * the order history: website orders by placement time, and return lines by the
 * order line they give back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE website_product_sales (
                website_product_id BIGINT PRIMARY KEY REFERENCES website_products (id) ON DELETE CASCADE,
                website_id BIGINT NOT NULL REFERENCES websites (id) ON DELETE CASCADE,
                net_units INTEGER NOT NULL,
                order_count INTEGER NOT NULL,
                window_starts_at TIMESTAMP NOT NULL,
                refreshed_at TIMESTAMP NOT NULL,
                CONSTRAINT website_product_sales_sold_something CHECK (net_units > 0 AND order_count > 0)
            );

            CREATE INDEX website_product_sales_ranking
                ON website_product_sales (website_id, net_units DESC, order_count DESC, website_product_id);

            CREATE INDEX orders_website_sales_window
                ON orders (placed_at)
                WHERE website_id IS NOT NULL;

            CREATE INDEX order_return_items_order_item_id_index
                ON order_return_items (order_item_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS order_return_items_order_item_id_index;
            DROP INDEX IF EXISTS orders_website_sales_window;
            DROP TABLE IF EXISTS website_product_sales;
        SQL);
    }
};
