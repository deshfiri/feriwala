<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which Product and variation an allocation's source was catalogued under, and
 * how it came to be offered, frozen when the allocation is made.
 *
 * An allocation already points at its Supplier offer or stock item, whose own
 * Product never changes, but "which Product did staff actually pick from" is
 * the answer a later question ("why was this Supplier paid for that order?")
 * needs on the allocation row itself -- not inferred from links that staff may
 * add or remove afterwards. The columns are locked with the rest of the
 * allocation's identity, so linking or unlinking Products later can never
 * rewrite a historical allocation.
 *
 * Nullable and additive: allocations made before this keep all three null and
 * are explained, as before, by their offer or stock item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_allocations', function (Blueprint $table) {
            $table->foreignId('source_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('source_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            // `exact` (the ordered Product itself), `linked_product` (a Product
            // linked as the same Product) or `linked` (a source staff
            // confirmed one by one).
            $table->string('source_match_kind', 24)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_item_allocations
                ADD CONSTRAINT order_item_allocations_source_snapshot_is_coherent CHECK (
                    (source_product_id IS NULL) = (source_match_kind IS NULL)
                    AND (source_product_variant_id IS NULL OR source_product_id IS NOT NULL)
                );

            DROP TRIGGER IF EXISTS order_item_allocations_locked_columns ON order_item_allocations;

            CREATE TRIGGER order_item_allocations_locked_columns
                BEFORE UPDATE OF
                    public_id, order_id, order_item_id, source_type, warehouse_id, linked_stock_item_id,
                    supplier_id, supplier_offer_id, supplier_offer_price_change_id, stock_reservation_id,
                    quantity, unit_cost, platform_rate, expected_margin, currency_code, idempotency_key,
                    override_by, override_at, override_reason,
                    source_product_id, source_product_variant_id, source_match_kind
                ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_id', 'order_item_id', 'source_type', 'warehouse_id', 'linked_stock_item_id',
                    'supplier_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'stock_reservation_id',
                    'quantity', 'unit_cost', 'platform_rate', 'expected_margin', 'currency_code', 'idempotency_key',
                    'override_by', 'override_at', 'override_reason',
                    'source_product_id', 'source_product_variant_id', 'source_match_kind'
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
                    quantity, unit_cost, platform_rate, expected_margin, currency_code, idempotency_key,
                    override_by, override_at, override_reason
                ON order_item_allocations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'order_id', 'order_item_id', 'source_type', 'warehouse_id', 'linked_stock_item_id',
                    'supplier_id', 'supplier_offer_id', 'supplier_offer_price_change_id', 'stock_reservation_id',
                    'quantity', 'unit_cost', 'platform_rate', 'expected_margin', 'currency_code', 'idempotency_key',
                    'override_by', 'override_at', 'override_reason'
                );

            ALTER TABLE order_item_allocations DROP CONSTRAINT IF EXISTS order_item_allocations_source_snapshot_is_coherent;
        SQL);

        Schema::table('order_item_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_product_variant_id');
            $table->dropConstrainedForeignId('source_product_id');
            $table->dropColumn('source_match_kind');
        });
    }
};
