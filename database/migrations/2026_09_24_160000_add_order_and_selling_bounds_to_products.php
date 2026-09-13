<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order-quantity and selling-price bounds (§11.1).
 *
 * Two different audiences. The order quantities bound what a business account
 * may put in one wholesale order (§14). The selling prices are what a partner is
 * told to sell at and the range their own storefront price must stay inside
 * (§15.1) — they are not what Feriwala charges the partner, which is the
 * wholesale price.
 *
 * Every bound is optional except the minimum order quantity, and "no bound" is
 * null rather than zero: zero is a real price, and conflating it with "unset"
 * would make a free product and an unpriced one indistinguishable.
 *
 * The ordering rules are CHECK constraints as well as validation, because a
 * minimum above its maximum is a product nobody can order or sell at any
 * figure, and a write that bypasses the form must not be able to create one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('min_order_quantity')->default(1)->after('wholesale_price_minor');
            $table->unsignedInteger('max_order_quantity')->nullable()->after('min_order_quantity');

            $table->bigInteger('suggested_selling_price_minor')->nullable()->after('max_order_quantity');
            $table->bigInteger('minimum_selling_price_minor')->nullable()->after('suggested_selling_price_minor');
            $table->bigInteger('maximum_selling_price_minor')->nullable()->after('minimum_selling_price_minor');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_min_order_quantity_positive CHECK (min_order_quantity >= 1),
                ADD CONSTRAINT products_order_quantity_range
                    CHECK (max_order_quantity IS NULL OR max_order_quantity >= min_order_quantity),
                ADD CONSTRAINT products_selling_prices_not_negative CHECK (
                    (suggested_selling_price_minor IS NULL OR suggested_selling_price_minor >= 0)
                    AND (minimum_selling_price_minor IS NULL OR minimum_selling_price_minor >= 0)
                    AND (maximum_selling_price_minor IS NULL OR maximum_selling_price_minor >= 0)
                ),
                ADD CONSTRAINT products_selling_price_range CHECK (
                    (minimum_selling_price_minor IS NULL OR maximum_selling_price_minor IS NULL
                        OR minimum_selling_price_minor <= maximum_selling_price_minor)
                    AND (suggested_selling_price_minor IS NULL OR minimum_selling_price_minor IS NULL
                        OR suggested_selling_price_minor >= minimum_selling_price_minor)
                    AND (suggested_selling_price_minor IS NULL OR maximum_selling_price_minor IS NULL
                        OR suggested_selling_price_minor <= maximum_selling_price_minor)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                DROP CONSTRAINT IF EXISTS products_min_order_quantity_positive,
                DROP CONSTRAINT IF EXISTS products_order_quantity_range,
                DROP CONSTRAINT IF EXISTS products_selling_prices_not_negative,
                DROP CONSTRAINT IF EXISTS products_selling_price_range;
        SQL);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'min_order_quantity',
                'max_order_quantity',
                'suggested_selling_price_minor',
                'minimum_selling_price_minor',
                'maximum_selling_price_minor',
            ]);
        });
    }
};
