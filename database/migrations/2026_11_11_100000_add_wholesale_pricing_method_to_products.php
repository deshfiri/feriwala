<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a wholesale price is arrived at: typed (`fixed`) or base cost plus a
 * markup percentage (`percentage`), on the product and on each variation.
 *
 * Every existing row is backfilled by the column default as `fixed` with no
 * percentage, so no current wholesale price changes. A percentage row must carry
 * its percentage, and a fixed row must not; a percentage variation must also
 * carry its own base cost, because it is never worked out from its product's.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_variants'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('wholesale_pricing_method', 16)->default('fixed');
                $blueprint->decimal('wholesale_markup_percentage', 6, 2)->nullable();
            });
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_wholesale_pricing_method_valid CHECK (wholesale_pricing_method IN ('fixed', 'percentage')),
                ADD CONSTRAINT products_wholesale_markup_range CHECK (wholesale_markup_percentage IS NULL OR (wholesale_markup_percentage >= 0 AND wholesale_markup_percentage <= 1000)),
                ADD CONSTRAINT products_wholesale_markup_matches_method CHECK ((wholesale_pricing_method = 'percentage') = (wholesale_markup_percentage IS NOT NULL));

            ALTER TABLE product_variants
                ADD CONSTRAINT product_variants_wholesale_pricing_method_valid CHECK (wholesale_pricing_method IN ('fixed', 'percentage')),
                ADD CONSTRAINT product_variants_wholesale_markup_range CHECK (wholesale_markup_percentage IS NULL OR (wholesale_markup_percentage >= 0 AND wholesale_markup_percentage <= 1000)),
                ADD CONSTRAINT product_variants_wholesale_markup_matches_method CHECK ((wholesale_pricing_method = 'percentage') = (wholesale_markup_percentage IS NOT NULL)),
                ADD CONSTRAINT product_variants_percentage_needs_own_cost CHECK (wholesale_pricing_method = 'fixed' OR (base_cost IS NOT NULL AND wholesale_price IS NOT NULL));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_wholesale_pricing_method_valid,
                DROP CONSTRAINT IF EXISTS products_wholesale_markup_range,
                DROP CONSTRAINT IF EXISTS products_wholesale_markup_matches_method;

            ALTER TABLE product_variants
                DROP CONSTRAINT IF EXISTS product_variants_wholesale_pricing_method_valid,
                DROP CONSTRAINT IF EXISTS product_variants_wholesale_markup_range,
                DROP CONSTRAINT IF EXISTS product_variants_wholesale_markup_matches_method,
                DROP CONSTRAINT IF EXISTS product_variants_percentage_needs_own_cost;
        SQL);

        foreach (['products', 'product_variants'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['wholesale_pricing_method', 'wholesale_markup_percentage']);
            });
        }
    }
};
