<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records exactly which stock item a warehouse allocation actually
 * represents, for the cross-catalogue case a confirmed
 * `ProductSourceLink` allows: the order line's own product identifies a
 * same-catalogue warehouse allocation (the historical, only case this
 * column didn't exist for), but a linked allocation draws on a *different*
 * product's stock, which the allocation row otherwise has no way to say.
 *
 * Nullable and additive only — every existing exact-match warehouse
 * allocation leaves it null and is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_allocations', function ($table) {
            $table->foreignId('linked_stock_item_id')->nullable()->after('warehouse_id')
                ->constrained('stock_items')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_item_allocations_locked_columns ON order_item_allocations;

            CREATE TRIGGER order_item_allocations_locked_columns
                BEFORE UPDATE OF
                    public_id, order_id, order_item_id, source_type, warehouse_id, linked_stock_item_id,
                    supplier_id, supplier_offer_id, supplier_offer_price_change_id, stock_reservation_id,
                    quantity, unit_cost, platform_rate, expected_margin, currency_code, idempotency_key
                ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_id', 'order_item_id', 'source_type', 'warehouse_id', 'linked_stock_item_id',
                    'supplier_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'stock_reservation_id',
                    'quantity', 'unit_cost', 'platform_rate', 'expected_margin', 'currency_code', 'idempotency_key'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS order_item_allocations_locked_columns ON order_item_allocations;

            CREATE TRIGGER order_item_allocations_locked_columns
                BEFORE UPDATE OF
                    public_id, order_id, order_item_id, source_type, warehouse_id,
                    supplier_id, supplier_offer_id, supplier_offer_price_change_id, stock_reservation_id,
                    quantity, unit_cost, platform_rate, expected_margin, currency_code, idempotency_key
                ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_id', 'order_item_id', 'source_type', 'warehouse_id',
                    'supplier_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'stock_reservation_id',
                    'quantity', 'unit_cost', 'platform_rate', 'expected_margin', 'currency_code', 'idempotency_key'
                );
        SQL);

        Schema::table('order_item_allocations', function ($table) {
            $table->dropConstrainedForeignId('linked_stock_item_id');
        });
    }
};
