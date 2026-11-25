<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Banij warehouse each Partner cost lot is physically held in.
 *
 * Partners hold no stock themselves: every unit they own sits in the Banij
 * warehouse that filled their wholesale purchase. A lot carries that
 * warehouse, proven from the purchase line's own allocation or central-stock
 * reservation, and how it was proven. A lot whose warehouse cannot be proven
 * stays null -- blocked for staff, never filled in with a guess.
 *
 * Set once: a lot's units do not move between warehouses by editing a row. Its
 * own trigger, separate from the lot's locked-column trigger, refuses any change
 * once the warehouse is known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_stock_cost_lots', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('product_variant_id')->constrained()->restrictOnDelete();
            // `wholesale_allocation`, `wholesale_reservation`, `stock_history`
            // or `return_receipt` (staff recorded where a return was received).
            $table->string('warehouse_evidence', 24)->nullable()->after('warehouse_id');
            $table->index(['partner_stock_item_id', 'warehouse_id', 'purchased_at', 'id'], 'partner_stock_cost_lots_item_warehouse_fifo');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE partner_stock_cost_lots
                ADD CONSTRAINT partner_stock_cost_lots_warehouse_has_evidence CHECK (
                    (warehouse_id IS NULL) = (warehouse_evidence IS NULL)
                ),
                ADD CONSTRAINT partner_stock_cost_lots_warehouse_evidence_known CHECK (
                    warehouse_evidence IS NULL
                    OR warehouse_evidence IN ('wholesale_allocation', 'wholesale_reservation', 'stock_history', 'return_receipt')
                );

            CREATE OR REPLACE FUNCTION feriwala_partner_lot_warehouse_set_once() RETURNS trigger AS $$
            BEGIN
                IF OLD.warehouse_id IS NOT NULL AND (
                    NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id
                    OR NEW.warehouse_evidence IS DISTINCT FROM OLD.warehouse_evidence
                ) THEN
                    RAISE EXCEPTION 'partner cost lot % is already held in warehouse %', OLD.id, OLD.warehouse_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER partner_stock_cost_lots_warehouse_set_once
                BEFORE UPDATE OF warehouse_id, warehouse_evidence ON partner_stock_cost_lots
                FOR EACH ROW EXECUTE FUNCTION feriwala_partner_lot_warehouse_set_once();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS partner_stock_cost_lots_warehouse_set_once ON partner_stock_cost_lots;
            DROP FUNCTION IF EXISTS feriwala_partner_lot_warehouse_set_once();
            ALTER TABLE partner_stock_cost_lots
                DROP CONSTRAINT IF EXISTS partner_stock_cost_lots_warehouse_evidence_known,
                DROP CONSTRAINT IF EXISTS partner_stock_cost_lots_warehouse_has_evidence;
        SQL);

        Schema::table('partner_stock_cost_lots', function (Blueprint $table) {
            $table->dropIndex('partner_stock_cost_lots_item_warehouse_fifo');
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('warehouse_evidence');
        });
    }
};
