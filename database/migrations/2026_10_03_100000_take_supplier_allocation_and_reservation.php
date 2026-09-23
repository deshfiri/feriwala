<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier offer allocation on order lines, and Supplier availability inside
 * the reservation lifecycle (D25, P13-21, P13-28).
 *
 * **Not a second reservation system.** A reservation is still one row in
 * `stock_reservations`, ended once through the one reservation service; it now
 * names exactly one *source* — a central `stock_items` row or a
 * `supplier_offer_stock` row — and the database refuses one that names both or
 * neither. Supplier stock keeps the same buckets central stock has
 * (available, reserved, processing, sold, returned, damaged) so the same
 * lifecycle reads the same way, and every change is one append-only
 * `supplier_stock_movements` row.
 *
 * The order line snapshots the Supplier and the exact price version it was
 * allocated at. `order_items` is already a snapshot the database refuses to
 * update or delete, so the Supplier columns are immutable from the moment the
 * line is written, and CHECKs hold the arithmetic (margin = Platform Rate −
 * Supplier Rate, never negative, one whole line to one offer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_offer_stock', function (Blueprint $table) {
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedInteger('processing_quantity')->default(0);
            $table->unsignedInteger('sold_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
        });

        Schema::table('supplier_stock_movements', function (Blueprint $table) {
            $table->unsignedInteger('moved_quantity')->nullable();
            $table->string('from_bucket', 16)->nullable();
            $table->string('to_bucket', 16)->nullable();
            $table->jsonb('buckets_before')->nullable();
            $table->jsonb('buckets_after')->nullable();
            $table->foreignId('stock_reservation_id')->nullable()->constrained('stock_reservations')->restrictOnDelete();
            $table->string('idempotency_key', 160)->nullable();
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->foreignId('supplier_offer_stock_id')->nullable()->constrained('supplier_offer_stock')->restrictOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_offer_id')->nullable()->constrained('supplier_offers')->restrictOnDelete();
            $table->foreignId('supplier_offer_price_change_id')->nullable()->constrained('supplier_offer_price_changes')->restrictOnDelete();
            $table->bigInteger('supplier_rate_minor')->nullable();
            $table->bigInteger('platform_rate_minor')->nullable();
            $table->bigInteger('platform_margin_minor')->nullable();
            $table->char('supplier_currency_code', 3)->nullable();
            $table->unsignedInteger('supplier_allocated_quantity')->nullable();
            $table->timestamp('supplier_allocated_at')->nullable();
        });

        Schema::table('order_return_items', function (Blueprint $table) {
            $table->foreignId('supplier_stock_movement_id')->nullable()->constrained('supplier_stock_movements')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_offer_stock
                ADD CONSTRAINT supplier_offer_stock_buckets_not_negative CHECK (
                    reserved_quantity >= 0 AND processing_quantity >= 0 AND sold_quantity >= 0
                    AND returned_quantity >= 0 AND damaged_quantity >= 0
                );

            ALTER TABLE supplier_stock_movements
                DROP CONSTRAINT supplier_stock_movements_source_known;

            ALTER TABLE supplier_stock_movements
                ADD CONSTRAINT supplier_stock_movements_source_known CHECK (
                    source IN (
                        'initial', 'supplier_update_approved', 'staff_adjustment',
                        'reservation', 'reservation_committed', 'reservation_released',
                        'reservation_expired', 'delivered', 'return_received'
                    )
                ),
                ADD CONSTRAINT supplier_stock_movements_buckets_known CHECK (
                    (from_bucket IS NULL OR from_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                    AND (to_bucket IS NULL OR to_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                );

            -- A retried command is answered by the movement it already wrote.
            CREATE UNIQUE INDEX supplier_stock_movements_one_per_idempotency_key
                ON supplier_stock_movements (idempotency_key) WHERE idempotency_key IS NOT NULL;

            CREATE INDEX supplier_stock_movements_by_reservation
                ON supplier_stock_movements (stock_reservation_id) WHERE stock_reservation_id IS NOT NULL;

            /*
             * A reservation holds units of exactly one source: a central stock
             * item, or one Supplier offer's stock. Never both, never neither — and
             * never an account's allocation, which is a central-stock concept.
             */
            ALTER TABLE stock_reservations
                ALTER COLUMN stock_item_id DROP NOT NULL;

            ALTER TABLE stock_reservations
                ADD CONSTRAINT stock_reservations_has_exactly_one_source CHECK (
                    num_nonnulls(stock_item_id, supplier_offer_stock_id) = 1
                ),
                ADD CONSTRAINT stock_reservations_supplier_source_is_not_allocated CHECK (
                    supplier_offer_stock_id IS NULL OR stock_allocation_id IS NULL
                );

            CREATE INDEX stock_reservations_by_supplier_source
                ON stock_reservations (supplier_offer_stock_id, status) WHERE supplier_offer_stock_id IS NOT NULL;

            CREATE TRIGGER stock_reservations_supplier_source_locked
                BEFORE UPDATE OF supplier_offer_stock_id ON stock_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('supplier_offer_stock_id');

            /*
             * A Supplier-backed order line is all or nothing: every snapshot
             * column, or none of them. One whole line is one offer (never split),
             * and the arithmetic is held by the database as well as the action.
             */
            CREATE INDEX order_items_by_supplier ON order_items (supplier_id) WHERE supplier_id IS NOT NULL;
            CREATE INDEX order_items_by_supplier_offer ON order_items (supplier_offer_id) WHERE supplier_offer_id IS NOT NULL;

            ALTER TABLE order_items
                ADD CONSTRAINT order_items_supplier_snapshot_is_complete CHECK (
                    num_nonnulls(
                        supplier_id, supplier_offer_id, supplier_offer_price_change_id,
                        supplier_rate_minor, platform_rate_minor, platform_margin_minor,
                        supplier_currency_code, supplier_allocated_quantity, supplier_allocated_at
                    ) IN (0, 9)
                ),
                ADD CONSTRAINT order_items_supplier_rates_valid CHECK (
                    supplier_rate_minor IS NULL OR (
                        supplier_rate_minor >= 0
                        AND platform_rate_minor >= supplier_rate_minor
                        AND platform_margin_minor = platform_rate_minor - supplier_rate_minor
                    )
                ),
                ADD CONSTRAINT order_items_supplier_line_is_never_split CHECK (
                    supplier_allocated_quantity IS NULL OR supplier_allocated_quantity = quantity
                ),
                ADD CONSTRAINT order_items_supplier_currency_matches_line CHECK (
                    supplier_currency_code IS NULL OR supplier_currency_code = currency_code
                );

            -- The offer named must be that Supplier's, for that very product and
            -- variation, and the price version must be that offer's own.
            CREATE OR REPLACE FUNCTION feriwala_order_item_supplier_matches_offer() RETURNS trigger AS $$
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
                      AND supplier_rate_minor = NEW.supplier_rate_minor
                      AND platform_rate_minor = NEW.platform_rate_minor
                ) THEN
                    RAISE EXCEPTION 'an order line''s rates must be the ones recorded in its price version'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER order_items_supplier_matches_offer
                BEFORE INSERT ON order_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_order_item_supplier_matches_offer();

            /*
             * A returned line is restored into central stock or into Supplier
             * stock — one movement, in one place, once.
             */
            ALTER TABLE order_return_items
                DROP CONSTRAINT order_return_items_restoration_is_complete;

            ALTER TABLE order_return_items
                ADD CONSTRAINT order_return_items_restoration_is_complete CHECK (
                    (stock_movement_id IS NULL AND supplier_stock_movement_id IS NULL AND restored_at IS NULL)
                    OR (num_nonnulls(stock_movement_id, supplier_stock_movement_id) = 1 AND restored_at IS NOT NULL
                        AND warehouse_id IS NOT NULL AND disposition IS NOT NULL)
                );

            CREATE UNIQUE INDEX order_return_items_one_supplier_restoration
                ON order_return_items (supplier_stock_movement_id) WHERE supplier_stock_movement_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS order_return_items_one_supplier_restoration;
            ALTER TABLE order_return_items DROP CONSTRAINT IF EXISTS order_return_items_restoration_is_complete;
            ALTER TABLE order_return_items
                ADD CONSTRAINT order_return_items_restoration_is_complete CHECK (
                    (stock_movement_id IS NULL AND restored_at IS NULL)
                    OR (stock_movement_id IS NOT NULL AND restored_at IS NOT NULL
                        AND warehouse_id IS NOT NULL AND disposition IS NOT NULL)
                );

            DROP TRIGGER IF EXISTS order_items_supplier_matches_offer ON order_items;
            DROP FUNCTION IF EXISTS feriwala_order_item_supplier_matches_offer();
            DROP INDEX IF EXISTS order_items_by_supplier_offer;
            DROP INDEX IF EXISTS order_items_by_supplier;
            ALTER TABLE order_items
                DROP CONSTRAINT IF EXISTS order_items_supplier_currency_matches_line,
                DROP CONSTRAINT IF EXISTS order_items_supplier_line_is_never_split,
                DROP CONSTRAINT IF EXISTS order_items_supplier_rates_valid,
                DROP CONSTRAINT IF EXISTS order_items_supplier_snapshot_is_complete;

            DROP TRIGGER IF EXISTS stock_reservations_supplier_source_locked ON stock_reservations;
            DROP INDEX IF EXISTS stock_reservations_by_supplier_source;
            ALTER TABLE stock_reservations
                DROP CONSTRAINT IF EXISTS stock_reservations_supplier_source_is_not_allocated,
                DROP CONSTRAINT IF EXISTS stock_reservations_has_exactly_one_source;
        SQL);

        Schema::table('order_return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_stock_movement_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_offer_price_change_id');
            $table->dropConstrainedForeignId('supplier_offer_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn([
                'supplier_rate_minor', 'platform_rate_minor', 'platform_margin_minor',
                'supplier_currency_code', 'supplier_allocated_quantity', 'supplier_allocated_at',
            ]);
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_offer_stock_id');
        });

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS supplier_stock_movements_by_reservation;
            DROP INDEX IF EXISTS supplier_stock_movements_one_per_idempotency_key;
            ALTER TABLE supplier_stock_movements
                DROP CONSTRAINT IF EXISTS supplier_stock_movements_buckets_known,
                DROP CONSTRAINT IF EXISTS supplier_stock_movements_source_known;
        SQL);

        Schema::table('supplier_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_reservation_id');
            $table->dropColumn(['moved_quantity', 'from_bucket', 'to_bucket', 'buckets_before', 'buckets_after', 'idempotency_key']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_stock_movements
                ADD CONSTRAINT supplier_stock_movements_source_known CHECK (
                    source IN ('initial', 'supplier_update_approved', 'staff_adjustment')
                );
            ALTER TABLE supplier_offer_stock DROP CONSTRAINT IF EXISTS supplier_offer_stock_buckets_not_negative;
        SQL);

        Schema::table('supplier_offer_stock', function (Blueprint $table) {
            $table->dropColumn(['reserved_quantity', 'processing_quantity', 'sold_quantity', 'returned_quantity', 'damaged_quantity']);
        });
    }
};
