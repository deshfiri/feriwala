<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier availability can never be negative (D25, P13-15).
 *
 * `unsignedInteger()` documents intent but Postgres has no unsigned type, so
 * the original stock migration enforced nothing: a negative quantity could be
 * written by any code path that forgot to check. These CHECKs make the
 * guarantee the database's own, on the current quantity, on a Supplier's
 * requested quantity, and on both sides of every recorded movement. A new
 * migration rather than an edit to `take_supplier_stock`, already committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_offer_stock
                ADD CONSTRAINT supplier_offer_stock_quantity_not_negative CHECK (quantity >= 0);

            ALTER TABLE supplier_stock_updates
                ADD CONSTRAINT supplier_stock_updates_quantity_not_negative CHECK (requested_quantity >= 0);

            ALTER TABLE supplier_stock_movements
                ADD CONSTRAINT supplier_stock_movements_quantities_not_negative CHECK (
                    quantity_before >= 0 AND quantity_after >= 0
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_stock_movements DROP CONSTRAINT IF EXISTS supplier_stock_movements_quantities_not_negative;
            ALTER TABLE supplier_stock_updates DROP CONSTRAINT IF EXISTS supplier_stock_updates_quantity_not_negative;
            ALTER TABLE supplier_offer_stock DROP CONSTRAINT IF EXISTS supplier_offer_stock_quantity_not_negative;
        SQL);
    }
};
