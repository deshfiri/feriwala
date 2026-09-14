<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central stock set aside for one business account (§19: user-allocated stock,
 * P3-30).
 *
 * §19 lists user-allocated stock beside the six buckets, and it is one: an
 * `allocated` figure on each stock item, holding units that have left available
 * — so no other account's order, and no other website's availability, can reach
 * them — and that only the account they were set aside for can order.
 *
 * `stock_allocations` says whose they are: one row per account per stock item,
 * holding what is still set aside and not yet reserved. The item's `allocated`
 * figure is always the sum of those rows, and the database holds that at commit —
 * a unit counted as allocated with nobody it is allocated to would be stock that
 * no order can ever take.
 *
 * A reservation remembers the account it was made for and, when it drew on an
 * allocation, which one, so ending it gives the units back to the account rather
 * than to everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->integer('allocated')->default(0);
        });

        Schema::create('stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();

            // Set aside for the account and not yet reserved by one of its orders.
            $table->integer('quantity')->default(0);

            $table->timestamps();

            $table->unique(['stock_item_id', 'business_account_id']);
            $table->index(['business_account_id', 'stock_item_id']);
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            // The account whose order this is, when the order came from one.
            $table->foreignId('business_account_id')->nullable()->constrained('business_accounts')->restrictOnDelete();

            // The allocation it drew on, when it did not draw on shared stock.
            $table->foreignId('stock_allocation_id')->nullable()->constrained('stock_allocations')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_items
                ADD CONSTRAINT stock_items_allocated_not_negative CHECK (allocated >= 0);

            ALTER TABLE stock_allocations
                ADD CONSTRAINT stock_allocations_quantity_not_negative CHECK (quantity >= 0);

            ALTER TABLE stock_reservations
                ADD CONSTRAINT stock_reservations_allocation_names_account CHECK (
                    stock_allocation_id IS NULL OR business_account_id IS NOT NULL
                );

            ALTER TABLE stock_movements
                DROP CONSTRAINT stock_movements_type_known,
                DROP CONSTRAINT stock_movements_buckets_known;

            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_type_known CHECK (type IN (
                    'adjustment', 'reservation', 'reservation_released',
                    'reservation_expired', 'reservation_committed',
                    'allocation', 'allocation_released'
                )),
                ADD CONSTRAINT stock_movements_buckets_known CHECK (
                    (from_bucket IS NULL OR from_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged', 'allocated'))
                    AND (to_bucket IS NULL OR to_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged', 'allocated'))
                );

            -- An item's allocated figure is the sum of its allocations, checked at
            -- commit: the ledger moves the figure and the allocation row changes in
            -- two statements of one transaction.
            CREATE OR REPLACE FUNCTION feriwala_stock_allocations_add_up() RETURNS trigger AS $$
            DECLARE
                item_id bigint;
                held integer;
                allocated_total integer;
            BEGIN
                IF TG_TABLE_NAME = 'stock_items' THEN
                    item_id := NEW.id;
                ELSE
                    item_id := NEW.stock_item_id;
                END IF;

                SELECT allocated INTO held FROM stock_items WHERE id = item_id;
                SELECT COALESCE(SUM(quantity), 0) INTO allocated_total FROM stock_allocations WHERE stock_item_id = item_id;

                IF held IS DISTINCT FROM allocated_total THEN
                    RAISE EXCEPTION 'stock item % counts % allocated, but its allocations hold %', item_id, held, allocated_total
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER stock_allocations_add_up
                AFTER INSERT OR UPDATE ON stock_allocations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION feriwala_stock_allocations_add_up();

            CREATE CONSTRAINT TRIGGER stock_items_allocated_adds_up
                AFTER UPDATE OF allocated ON stock_items
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION feriwala_stock_allocations_add_up();

            -- A reservation drawing on an allocation draws on that account's
            -- allocation of that very item.
            CREATE OR REPLACE FUNCTION feriwala_stock_reservation_allocation_matches() RETURNS trigger AS $$
            BEGIN
                IF NEW.stock_allocation_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM stock_allocations
                    WHERE id = NEW.stock_allocation_id
                      AND stock_item_id = NEW.stock_item_id
                      AND business_account_id = NEW.business_account_id
                ) THEN
                    RAISE EXCEPTION 'a reservation can only draw on its own account''s allocation of the same stock item'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_reservations_allocation_matches
                BEFORE INSERT ON stock_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_stock_reservation_allocation_matches();

            CREATE TRIGGER stock_reservations_account_locked
                BEFORE UPDATE OF business_account_id, stock_allocation_id ON stock_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('business_account_id', 'stock_allocation_id');

            CREATE TRIGGER stock_allocations_locked_columns
                BEFORE UPDATE OF public_id, stock_item_id, business_account_id ON stock_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'stock_item_id', 'business_account_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_items_allocated_adds_up ON stock_items;
            DROP TRIGGER IF EXISTS stock_reservations_allocation_matches ON stock_reservations;
            DROP TRIGGER IF EXISTS stock_reservations_account_locked ON stock_reservations;

            ALTER TABLE stock_movements
                DROP CONSTRAINT stock_movements_type_known,
                DROP CONSTRAINT stock_movements_buckets_known;

            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_type_known CHECK (type IN (
                    'adjustment', 'reservation', 'reservation_released',
                    'reservation_expired', 'reservation_committed'
                )),
                ADD CONSTRAINT stock_movements_buckets_known CHECK (
                    (from_bucket IS NULL OR from_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                    AND (to_bucket IS NULL OR to_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                );

            ALTER TABLE stock_reservations DROP CONSTRAINT IF EXISTS stock_reservations_allocation_names_account;
        SQL);

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_allocation_id');
            $table->dropConstrainedForeignId('business_account_id');
        });

        Schema::dropIfExists('stock_allocations');

        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn('allocated');
        });

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_stock_allocations_add_up();
            DROP FUNCTION IF EXISTS feriwala_stock_reservation_allocation_matches();
        SQL);
    }
};
