<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Non-Conditional line's declared resale/COD amount, while it is still a
 * draft on the cart.
 *
 * Mutable, unlike its `order_items` counterpart — a cart line is reconsidered
 * freely until checkout confirms it, and `PlaceWholesaleOrder` copies the
 * value into the order's own, immutable snapshot at placement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->decimal('resale_amount', 19, 2)->nullable()->after('unit_price_seen');
        });

        DB::unprepared(
            'ALTER TABLE cart_items ADD CONSTRAINT cart_items_resale_amount_not_negative '.
            'CHECK (resale_amount IS NULL OR resale_amount >= 0);'
        );
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropColumn('resale_amount');
        });
    }
};
