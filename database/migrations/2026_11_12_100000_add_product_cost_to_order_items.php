<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What Feriwala is owed for a storefront line it filled from central stock for a
 * Non-Conditional account (the account never bought it), frozen at placement.
 *
 * Needs no locked-columns trigger of its own: `order_items_are_snapshots`
 * already refuses every UPDATE and DELETE on this table outright, so a
 * nullable column added here is immutable the moment it exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('product_cost', 19, 2)->nullable()->after('resale_amount');
        });

        DB::unprepared(
            'ALTER TABLE order_items ADD CONSTRAINT order_items_product_cost_not_negative '.
            'CHECK (product_cost IS NULL OR product_cost >= 0);'
        );
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('product_cost');
        });
    }
};
