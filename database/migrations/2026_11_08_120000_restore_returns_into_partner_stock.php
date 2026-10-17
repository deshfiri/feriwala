<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A returned storefront line goes back into the Partner's own stock (D34).
 *
 * The third place a returned line can be restored — a warehouse, a Supplier's
 * offer, or the Partner's stock the line was sold from — still exactly one
 * movement, in one place, once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_return_items', function (Blueprint $table) {
            $table->foreignId('partner_stock_movement_id')->nullable()->constrained('partner_stock_movements')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_return_items
                DROP CONSTRAINT order_return_items_restoration_is_complete;

            ALTER TABLE order_return_items
                ADD CONSTRAINT order_return_items_restoration_is_complete CHECK (
                    (stock_movement_id IS NULL AND supplier_stock_movement_id IS NULL
                        AND partner_stock_movement_id IS NULL AND restored_at IS NULL)
                    OR (num_nonnulls(stock_movement_id, supplier_stock_movement_id, partner_stock_movement_id) = 1
                        AND restored_at IS NOT NULL AND warehouse_id IS NOT NULL AND disposition IS NOT NULL)
                );

            CREATE UNIQUE INDEX order_return_items_one_partner_restoration
                ON order_return_items (partner_stock_movement_id) WHERE partner_stock_movement_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS order_return_items_one_partner_restoration;
            ALTER TABLE order_return_items DROP CONSTRAINT IF EXISTS order_return_items_restoration_is_complete;
            ALTER TABLE order_return_items
                ADD CONSTRAINT order_return_items_restoration_is_complete CHECK (
                    (stock_movement_id IS NULL AND supplier_stock_movement_id IS NULL AND restored_at IS NULL)
                    OR (num_nonnulls(stock_movement_id, supplier_stock_movement_id) = 1 AND restored_at IS NOT NULL
                        AND warehouse_id IS NOT NULL AND disposition IS NOT NULL)
                );
        SQL);

        Schema::table('order_return_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_stock_movement_id');
        });
    }
};
