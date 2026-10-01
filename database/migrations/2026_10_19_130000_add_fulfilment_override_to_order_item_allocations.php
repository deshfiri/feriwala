<?php

use App\Domain\Order\Actions\AllocateOrderLineSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a reallocation was forced past the point
 * {@see AllocateOrderLineSource::canReallocate()}
 * would otherwise have refused it — once fulfilment has reached picking or
 * the line has been handed to a courier (§20, §21).
 *
 * Nullable and additive: an ordinary allocation, which is almost all of
 * them, leaves all three null. Written once, at the same INSERT that creates
 * the overriding allocation, and never afterwards — added to the existing
 * locked-columns trigger rather than a separate guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_allocations', function ($table) {
            $table->foreignId('override_by')->nullable()->after('allocation_reason')->constrained('users')->restrictOnDelete();
            $table->timestamp('override_at')->nullable()->after('override_by');
            $table->text('override_reason')->nullable()->after('override_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_item_allocations
                ADD CONSTRAINT order_item_allocations_override_is_complete CHECK (
                    (override_by IS NULL AND override_at IS NULL AND override_reason IS NULL)
                    OR (override_by IS NOT NULL AND override_at IS NOT NULL AND override_reason IS NOT NULL)
                );

            DROP TRIGGER IF EXISTS order_item_allocations_locked_columns ON order_item_allocations;

            CREATE TRIGGER order_item_allocations_locked_columns
                BEFORE UPDATE OF
                    public_id, order_id, order_item_id, source_type, warehouse_id, linked_stock_item_id,
                    supplier_id, supplier_offer_id, supplier_offer_price_change_id, stock_reservation_id,
                    quantity, unit_cost, platform_rate, expected_margin, currency_code, idempotency_key,
                    override_by, override_at, override_reason
                ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_id', 'order_item_id', 'source_type', 'warehouse_id', 'linked_stock_item_id',
                    'supplier_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'stock_reservation_id',
                    'quantity', 'unit_cost', 'platform_rate', 'expected_margin', 'currency_code', 'idempotency_key',
                    'override_by', 'override_at', 'override_reason'
                );
        SQL);
    }

    public function down(): void
    {
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

        Schema::table('order_item_allocations', function ($table) {
            $table->dropConstrainedForeignId('override_by');
            $table->dropColumn(['override_at', 'override_reason']);
        });
    }
};
