<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalizes a listing item's supply mode onto the offer it becomes on
 * approval (Supplier Bulk Product Listing batch) -- the same pattern this
 * table already uses for `supplier_rate`/`platform_rate`: the figure the
 * Supplier proposed lives on the listing item forever, and the value that
 * actually governs the live offer is copied once, at approval time, by
 * `DecideSupplierListing::approveItem()`.
 *
 * This is what lets the order allocation panel show supply mode, lead time
 * and capacity for a Supplier source without joining back through a decided
 * listing item every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->string('supply_mode', 16)->default('ready_stock')->after('dropshipping_enabled');
            $table->unsignedInteger('lead_time_days')->nullable()->after('supply_mode');
            $table->unsignedInteger('fulfilment_capacity')->nullable()->after('lead_time_days');
            $table->timestamp('expected_availability_at')->nullable()->after('fulfilment_capacity');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_offers
                ADD CONSTRAINT supplier_offers_supply_mode_known CHECK (
                    supply_mode IN ('ready_stock', 'on_demand', 'pre_order')
                ),
                ADD CONSTRAINT supplier_offers_capacity_not_negative CHECK (
                    fulfilment_capacity IS NULL OR fulfilment_capacity >= 0
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_offers
                DROP CONSTRAINT supplier_offers_capacity_not_negative,
                DROP CONSTRAINT supplier_offers_supply_mode_known;
        SQL);

        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->dropColumn(['supply_mode', 'lead_time_days', 'fulfilment_capacity', 'expected_availability_at']);
        });
    }
};
