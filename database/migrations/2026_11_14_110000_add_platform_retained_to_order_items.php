<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform keeps from a storefront line filled from central stock for
 * a Non-Conditional account: the suggested selling price times the quantity,
 * frozen when the order is placed.
 *
 * The partner's profit on such a line is the price the store actually sold it for
 * less this — never less the product's base cost — so the snapshot is what a
 * later settlement reads, and a product repriced tomorrow cannot change what an
 * order placed today earned. Nullable, because every line placed before this
 * existed has none and keeps settling the way it was placed.
 *
 * `order_items_are_snapshots` already refuses every UPDATE and DELETE on that
 * table, so the column is immutable the moment it exists.
 *
 * `order_proceeds_settlements.reversed_amount` is how much of a credited profit
 * has been taken back by returns, so a series of partial returns can never take
 * back more than was credited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('platform_retained_amount', 19, 2)->nullable();
        });

        Schema::table('order_proceeds_settlements', function (Blueprint $table): void {
            $table->decimal('reversed_amount', 19, 2)->default('0.00');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_platform_retained_not_negative
                CHECK (platform_retained_amount IS NULL OR platform_retained_amount >= 0);
            ALTER TABLE order_proceeds_settlements
                ADD CONSTRAINT order_proceeds_settlements_reversed_not_negative
                CHECK (reversed_amount >= 0);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE order_proceeds_settlements DROP CONSTRAINT IF EXISTS order_proceeds_settlements_reversed_not_negative;
            ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_platform_retained_not_negative;
        SQL);

        Schema::table('order_proceeds_settlements', function (Blueprint $table): void {
            $table->dropColumn('reversed_amount');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('platform_retained_amount');
        });
    }
};
