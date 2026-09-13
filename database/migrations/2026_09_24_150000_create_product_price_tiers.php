<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quantity-based wholesale pricing (§11.1, §13).
 *
 * A tier says "from this many units, this much each". There is no upper bound
 * column: a band ends where the next one starts, so two bands cannot overlap and
 * no quantity can fall into a gap between them — the shape makes both mistakes
 * impossible rather than something to validate.
 *
 * A tier belongs to the whole product, or to one variation that is priced
 * differently. Uniqueness of the starting quantity is per scope, and needs two
 * partial indexes rather than one: Postgres treats nulls as distinct, so a plain
 * unique index over (product, variant, quantity) would let the product-wide
 * scope hold "10 units" twice.
 *
 * Money is BIGINT minor units beside a currency (D4). An order records the unit
 * price it was placed at; changing a tier never rewrites what was already
 * bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->unsignedInteger('min_quantity');

            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('unit_price_minor');

            $table->timestamps();

            $table->index(['product_id', 'product_variant_id', 'min_quantity']);
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX product_price_tiers_product_quantity_unique
                ON product_price_tiers (product_id, min_quantity)
                WHERE product_variant_id IS NULL;

            CREATE UNIQUE INDEX product_price_tiers_variant_quantity_unique
                ON product_price_tiers (product_variant_id, min_quantity)
                WHERE product_variant_id IS NOT NULL;

            ALTER TABLE product_price_tiers
                -- A tier from one unit is just the base price under another name.
                ADD CONSTRAINT product_price_tiers_quantity_at_least_two CHECK (min_quantity >= 2),
                ADD CONSTRAINT product_price_tiers_price_not_negative CHECK (unit_price_minor >= 0);
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_tiers');
    }
};
