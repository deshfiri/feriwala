<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The history of every change to central stock (§19, P3-23).
 *
 * One row per movement of units into, out of, or between an item's buckets,
 * written in the same transaction as the figures it explains, with every
 * bucket's figure immediately before and after. A stock figure that changed
 * without a row here is a count nobody can account for, so nothing but the
 * stock ledger writes a bucket, and the ledger always writes one of these.
 *
 * Append-only, like every other record of what happened in this application:
 * a correction is a new movement, never an edited one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();

            // Denormalised from the item, so history reads by warehouse or SKU
            // without joining through items that may since have been renamed.
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            // Why it moved, and between which buckets. A missing `from` is stock
            // arriving; a missing `to` is stock leaving central stock altogether.
            $table->string('type', 40);
            $table->string('from_bucket', 20)->nullable();
            $table->string('to_bucket', 20)->nullable();
            $table->integer('quantity');

            // Every bucket's figure on either side of the movement.
            $table->jsonb('before');
            $table->jsonb('after');

            $table->text('reason')->nullable();

            // Users are never removed while their records stand (D18).
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();

            // What caused it — an adjustment, a reservation — by type and id.
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['stock_item_id', 'id']);
            $table->index(['product_id', 'id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock_movements
                ADD CONSTRAINT stock_movements_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT stock_movements_type_known CHECK (type IN (
                    'adjustment', 'reservation', 'reservation_released',
                    'reservation_expired', 'reservation_committed'
                )),
                ADD CONSTRAINT stock_movements_buckets_known CHECK (
                    (from_bucket IS NULL OR from_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                    AND (to_bucket IS NULL OR to_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                ),
                -- A movement goes somewhere: never from nothing to nothing, nor a bucket into itself.
                ADD CONSTRAINT stock_movements_moves_something CHECK (
                    (from_bucket IS NOT NULL OR to_bucket IS NOT NULL)
                    AND (from_bucket IS NULL OR to_bucket IS NULL OR from_bucket <> to_bucket)
                );

            CREATE TRIGGER stock_movements_no_update
                BEFORE UPDATE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER stock_movements_no_delete
                BEFORE DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_movements_no_update ON stock_movements;
            DROP TRIGGER IF EXISTS stock_movements_no_delete ON stock_movements;
        SQL);

        Schema::dropIfExists('stock_movements');
    }
};
