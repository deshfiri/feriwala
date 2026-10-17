<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock a Partner owns because they bought it (D34).
 *
 * A paid wholesale purchase gives the Partner the units; their storefront sells
 * those units and nothing else. This is a third source of quantity beside central
 * warehouse stock (§19) and Supplier offers (D25): nothing here reads or writes
 * `stock_items`, and the reservation lifecycle that already serves the other two
 * serves this one, through `stock_reservations.partner_stock_item_id`.
 *
 * One row per Partner, Product and exact Variation, holding the six buckets a
 * unit can be in. Every change is one row in the append-only
 * `partner_stock_movements`, written in the same transaction with each bucket's
 * figure before and after, so a figure nobody can account for cannot exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_stock_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->integer('available')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('processing')->default(0);
            $table->integer('sold')->default(0);
            $table->integer('returned')->default(0);
            $table->integer('damaged')->default(0);

            $table->timestamps();
        });

        Schema::create('partner_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_stock_item_id')->constrained('partner_stock_items')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('type', 40);
            $table->string('from_bucket', 20)->nullable();
            $table->string('to_bucket', 20)->nullable();
            $table->integer('quantity');
            $table->jsonb('before');
            $table->jsonb('after');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['partner_stock_item_id', 'id']);
            $table->index(['business_account_id', 'product_id']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->foreignId('partner_stock_item_id')->nullable()->constrained('partner_stock_items')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX partner_stock_items_one_per_unit
                ON partner_stock_items (business_account_id, product_id, COALESCE(product_variant_id, 0));

            ALTER TABLE partner_stock_items
                ADD CONSTRAINT partner_stock_items_never_negative CHECK (
                    available >= 0 AND reserved >= 0 AND processing >= 0
                    AND sold >= 0 AND returned >= 0 AND damaged >= 0
                );

            ALTER TABLE partner_stock_movements
                ADD CONSTRAINT partner_stock_movements_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT partner_stock_movements_type_known CHECK (type IN (
                    'purchase_received', 'purchase_reversed', 'reservation',
                    'reservation_released', 'reservation_expired', 'reservation_committed',
                    'return_received', 'adjustment'
                )),
                ADD CONSTRAINT partner_stock_movements_buckets_known CHECK (
                    (from_bucket IS NULL OR from_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                    AND (to_bucket IS NULL OR to_bucket IN ('available', 'reserved', 'processing', 'sold', 'returned', 'damaged'))
                ),
                ADD CONSTRAINT partner_stock_movements_moves_something CHECK (
                    (from_bucket IS NOT NULL OR to_bucket IS NOT NULL)
                    AND (from_bucket IS NULL OR to_bucket IS NULL OR from_bucket <> to_bucket)
                );

            CREATE TRIGGER partner_stock_movements_no_update
                BEFORE UPDATE ON partner_stock_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER partner_stock_movements_no_delete
                BEFORE DELETE ON partner_stock_movements
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            /*
             * A reservation holds units of exactly one source: a central stock
             * item, one Supplier offer's stock, or a Partner's own stock. Never
             * two, never none — and never an account's allocation, which is a
             * central-stock concept.
             */
            ALTER TABLE stock_reservations
                DROP CONSTRAINT stock_reservations_has_exactly_one_source;

            ALTER TABLE stock_reservations
                ADD CONSTRAINT stock_reservations_has_exactly_one_source CHECK (
                    num_nonnulls(stock_item_id, supplier_offer_stock_id, partner_stock_item_id) = 1
                ),
                ADD CONSTRAINT stock_reservations_partner_source_is_not_allocated CHECK (
                    partner_stock_item_id IS NULL OR stock_allocation_id IS NULL
                );

            CREATE INDEX stock_reservations_by_partner_source
                ON stock_reservations (partner_stock_item_id, status) WHERE partner_stock_item_id IS NOT NULL;

            CREATE TRIGGER stock_reservations_partner_source_locked
                BEFORE UPDATE OF partner_stock_item_id ON stock_reservations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('partner_stock_item_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS stock_reservations_partner_source_locked ON stock_reservations;
            DROP INDEX IF EXISTS stock_reservations_by_partner_source;

            ALTER TABLE stock_reservations
                DROP CONSTRAINT IF EXISTS stock_reservations_partner_source_is_not_allocated,
                DROP CONSTRAINT IF EXISTS stock_reservations_has_exactly_one_source;

            ALTER TABLE stock_reservations
                ADD CONSTRAINT stock_reservations_has_exactly_one_source CHECK (
                    num_nonnulls(stock_item_id, supplier_offer_stock_id) = 1
                );

            DROP TRIGGER IF EXISTS partner_stock_movements_no_delete ON partner_stock_movements;
            DROP TRIGGER IF EXISTS partner_stock_movements_no_update ON partner_stock_movements;
        SQL);

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_stock_item_id');
        });

        Schema::dropIfExists('partner_stock_movements');
        Schema::dropIfExists('partner_stock_items');
    }
};
