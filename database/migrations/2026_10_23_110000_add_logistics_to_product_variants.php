<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A variant's own logistics override (beta-critical batch, Commit 1).
 *
 * Every column is nullable and means "not overridden, use the product's own
 * figure" -- the same convention `wholesale_price`/`base_cost` already use on
 * this table (see `ProductVariant::effectiveWholesalePrice()`). `ships_by_box`
 * and `is_fragile` are nullable booleans here specifically so a variant can
 * leave them unset rather than being forced to restate the product's own
 * choice; `App\Domain\Catalog\Data\ProductLogistics::forVariant()` resolves
 * each field independently, so a variant can override just its own weight
 * while leaving every other figure inherited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('net_weight_grams')->nullable();
            $table->unsignedInteger('shipping_weight_grams')->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();

            $table->boolean('ships_by_box')->nullable();
            $table->unsignedInteger('pieces_per_box')->nullable();
            $table->unsignedInteger('box_weight_grams')->nullable();
            $table->decimal('box_length_cm', 8, 2)->nullable();
            $table->decimal('box_width_cm', 8, 2)->nullable();
            $table->decimal('box_height_cm', 8, 2)->nullable();

            $table->boolean('is_fragile')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_variants
                ADD CONSTRAINT product_variants_net_weight_positive CHECK (net_weight_grams IS NULL OR net_weight_grams > 0),
                ADD CONSTRAINT product_variants_shipping_weight_positive CHECK (shipping_weight_grams IS NULL OR shipping_weight_grams > 0),
                ADD CONSTRAINT product_variants_length_positive CHECK (length_cm IS NULL OR length_cm > 0),
                ADD CONSTRAINT product_variants_width_positive CHECK (width_cm IS NULL OR width_cm > 0),
                ADD CONSTRAINT product_variants_height_positive CHECK (height_cm IS NULL OR height_cm > 0),
                ADD CONSTRAINT product_variants_pieces_per_box_positive CHECK (pieces_per_box IS NULL OR pieces_per_box > 0),
                ADD CONSTRAINT product_variants_box_weight_positive CHECK (box_weight_grams IS NULL OR box_weight_grams > 0),
                ADD CONSTRAINT product_variants_box_length_positive CHECK (box_length_cm IS NULL OR box_length_cm > 0),
                ADD CONSTRAINT product_variants_box_width_positive CHECK (box_width_cm IS NULL OR box_width_cm > 0),
                ADD CONSTRAINT product_variants_box_height_positive CHECK (box_height_cm IS NULL OR box_height_cm > 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE product_variants
                DROP CONSTRAINT IF EXISTS product_variants_net_weight_positive,
                DROP CONSTRAINT IF EXISTS product_variants_shipping_weight_positive,
                DROP CONSTRAINT IF EXISTS product_variants_length_positive,
                DROP CONSTRAINT IF EXISTS product_variants_width_positive,
                DROP CONSTRAINT IF EXISTS product_variants_height_positive,
                DROP CONSTRAINT IF EXISTS product_variants_pieces_per_box_positive,
                DROP CONSTRAINT IF EXISTS product_variants_box_weight_positive,
                DROP CONSTRAINT IF EXISTS product_variants_box_length_positive,
                DROP CONSTRAINT IF EXISTS product_variants_box_width_positive,
                DROP CONSTRAINT IF EXISTS product_variants_box_height_positive;
        SQL);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'net_weight_grams', 'shipping_weight_grams',
                'length_cm', 'width_cm', 'height_cm',
                'ships_by_box', 'pieces_per_box', 'box_weight_grams',
                'box_length_cm', 'box_width_cm', 'box_height_cm',
                'is_fragile',
            ]);
        });
    }
};
