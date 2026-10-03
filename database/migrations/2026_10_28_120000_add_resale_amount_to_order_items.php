<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Non-Conditional line's declared resale/COD amount, frozen at placement.
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
            $table->decimal('resale_amount', 19, 2)->nullable()->after('line_total');
        });

        DB::unprepared(
            'ALTER TABLE order_items ADD CONSTRAINT order_items_resale_amount_not_negative '.
            'CHECK (resale_amount IS NULL OR resale_amount >= 0);'
        );
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('resale_amount');
        });
    }
};
