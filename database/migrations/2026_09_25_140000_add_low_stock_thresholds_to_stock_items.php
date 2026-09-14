<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Low-stock thresholds (§19, P3-29).
 *
 * A threshold belongs to a SKU in a warehouse, because that is what somebody
 * replenishes: "Dhaka is down to three kettles". Empty means nobody asked to be
 * told. `low_stock_alerted_at` remembers that the alert for the current shortfall
 * has already gone out, so a figure that sits below its threshold does not
 * notify on every movement — it notifies once when it falls, and is ready to do
 * so again once it has recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->integer('low_stock_threshold')->nullable()->after('damaged');
            $table->timestamp('low_stock_alerted_at')->nullable()->after('low_stock_threshold');
        });

        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT stock_items_low_stock_threshold_not_negative CHECK (low_stock_threshold IS NULL OR low_stock_threshold >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_items DROP CONSTRAINT IF EXISTS stock_items_low_stock_threshold_not_negative');

        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['low_stock_threshold', 'low_stock_alerted_at']);
        });
    }
};
