<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an order line requires of its fulfilment, frozen when the line is
 * written (Product Sourcing Groups).
 *
 * `order_items` is already immutable by trigger, so these columns can only
 * ever be set at insert -- exactly the guarantee wanted: a later change to a
 * group, a membership or a variant mapping never alters what an existing order
 * line asked for, and the allocation panel resolves candidates from these
 * columns, not from today's mappings.
 *
 * Existing lines keep all three null. They are never backfilled (that would be
 * guessing equivalence), and stay allocatable exactly as before -- the panel
 * shows them as unmatched / manual review.
 *
 * `sourcing_canonical_variant_id` is null for a canonical product with no
 * variations and for any unmatched line; the group and canonical product are
 * always set together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('sourcing_group_id')->nullable()->constrained('product_sourcing_groups')->restrictOnDelete();
            $table->foreignId('sourcing_canonical_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->foreignId('sourcing_canonical_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->index('sourcing_group_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_sourcing_snapshot_is_coherent CHECK (
                    (sourcing_group_id IS NULL) = (sourcing_canonical_product_id IS NULL)
                    AND (sourcing_canonical_variant_id IS NULL OR sourcing_group_id IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_sourcing_snapshot_is_coherent');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sourcing_canonical_variant_id');
            $table->dropConstrainedForeignId('sourcing_canonical_product_id');
            $table->dropConstrainedForeignId('sourcing_group_id');
        });
    }
};
