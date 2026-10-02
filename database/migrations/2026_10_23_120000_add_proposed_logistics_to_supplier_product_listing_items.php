<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Supplier's proposed logistics figures for one listing item (beta-critical
 * batch, Commit 1).
 *
 * The same shape and units as the central `products`/`product_variants`
 * columns -- whole grams, centimetres, integer piece counts -- but these are
 * a **proposal only**. Nothing here ever reaches `products` or
 * `product_variants` on its own; {@see
 * \App\Domain\Supplier\Actions\DecideSupplierListing::approveItem()} is the
 * one place a staff reviewer's own confirmed (or corrected) figures are
 * written onto the actual central record being connected, through the same
 * `ManageProducts`/`ManageVariants` write path every other catalogue edit
 * uses. All nullable: a Supplier is never required to propose logistics data
 * to list a product, the same as a Supplier is never required to declare a
 * stock quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_product_listing_items', function (Blueprint $table) {
            $table->unsignedInteger('proposed_net_weight_grams')->nullable();
            $table->unsignedInteger('proposed_shipping_weight_grams')->nullable();
            $table->decimal('proposed_length_cm', 8, 2)->nullable();
            $table->decimal('proposed_width_cm', 8, 2)->nullable();
            $table->decimal('proposed_height_cm', 8, 2)->nullable();
            $table->boolean('proposed_ships_by_box')->nullable();
            $table->unsignedInteger('proposed_pieces_per_box')->nullable();
            $table->unsignedInteger('proposed_box_weight_grams')->nullable();
            $table->decimal('proposed_box_length_cm', 8, 2)->nullable();
            $table->decimal('proposed_box_width_cm', 8, 2)->nullable();
            $table->decimal('proposed_box_height_cm', 8, 2)->nullable();
            $table->boolean('proposed_is_fragile')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listing_items
                ADD CONSTRAINT supplier_listing_items_proposed_net_weight_positive CHECK (proposed_net_weight_grams IS NULL OR proposed_net_weight_grams > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_shipping_weight_positive CHECK (proposed_shipping_weight_grams IS NULL OR proposed_shipping_weight_grams > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_length_positive CHECK (proposed_length_cm IS NULL OR proposed_length_cm > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_width_positive CHECK (proposed_width_cm IS NULL OR proposed_width_cm > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_height_positive CHECK (proposed_height_cm IS NULL OR proposed_height_cm > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_pieces_per_box_positive CHECK (proposed_pieces_per_box IS NULL OR proposed_pieces_per_box > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_box_weight_positive CHECK (proposed_box_weight_grams IS NULL OR proposed_box_weight_grams > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_box_length_positive CHECK (proposed_box_length_cm IS NULL OR proposed_box_length_cm > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_box_width_positive CHECK (proposed_box_width_cm IS NULL OR proposed_box_width_cm > 0),
                ADD CONSTRAINT supplier_listing_items_proposed_box_height_positive CHECK (proposed_box_height_cm IS NULL OR proposed_box_height_cm > 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listing_items
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_net_weight_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_shipping_weight_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_length_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_width_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_height_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_pieces_per_box_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_box_weight_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_box_length_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_box_width_positive,
                DROP CONSTRAINT IF EXISTS supplier_listing_items_proposed_box_height_positive;
        SQL);

        Schema::table('supplier_product_listing_items', function (Blueprint $table) {
            $table->dropColumn([
                'proposed_net_weight_grams', 'proposed_shipping_weight_grams',
                'proposed_length_cm', 'proposed_width_cm', 'proposed_height_cm',
                'proposed_ships_by_box', 'proposed_pieces_per_box', 'proposed_box_weight_grams',
                'proposed_box_length_cm', 'proposed_box_width_cm', 'proposed_box_height_cm',
                'proposed_is_fragile',
            ]);
        });
    }
};
