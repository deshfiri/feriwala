<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional stock declaration and explicit supply modes on a Supplier listing
 * item (Supplier Bulk Product Listing batch).
 *
 * Stock is not mandatory during listing: a Supplier may offer a product
 * before they have physically prepared it. `ready_stock` keeps today's
 * behaviour (a Supplier who has it may say how much); `on_demand` and
 * `pre_order` accept no confirmed quantity at all and instead require the
 * lead time and/or expected-availability information a buyer or staff needs
 * to judge the offer honestly. Missing stock is never read as zero or as an
 * unlimited guarantee -- it is simply absent, and the presentation layer
 * (never this schema) is what turns that into an honest label.
 *
 * `available_quantity`'s NOT NULL is dropped for this reason. The existing
 * `..._quantities_not_negative` CHECK used `GREATEST(available_quantity, 1)`
 * to bound `minimum_supply_quantity` -- Postgres's `GREATEST()` ignores a
 * NULL argument and returns `1`, which would have silently forced every
 * now-stockless item's minimum order quantity down to 1 the moment this
 * column went nullable. The constraint is dropped and re-added handling
 * NULL explicitly instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_product_listing_items', function (Blueprint $table) {
            $table->string('supply_mode', 16)->default('ready_stock')->after('status');
            $table->unsignedInteger('fulfilment_capacity')->nullable()->after('supply_mode');
            $table->timestamp('expected_availability_at')->nullable()->after('fulfilment_capacity');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listing_items
                ALTER COLUMN available_quantity DROP NOT NULL;

            ALTER TABLE supplier_product_listing_items
                DROP CONSTRAINT supplier_product_listing_items_quantities_not_negative;

            ALTER TABLE supplier_product_listing_items
                ADD CONSTRAINT supplier_product_listing_items_quantities_not_negative CHECK (
                    (available_quantity IS NULL OR available_quantity >= 0)
                    AND minimum_supply_quantity >= 1
                    AND (
                        available_quantity IS NULL
                        OR minimum_supply_quantity <= GREATEST(available_quantity, 1)
                    )
                ),
                ADD CONSTRAINT supplier_product_listing_items_supply_mode_known CHECK (
                    supply_mode IN ('ready_stock', 'on_demand', 'pre_order')
                ),
                ADD CONSTRAINT supplier_product_listing_items_on_demand_needs_lead_time CHECK (
                    supply_mode <> 'on_demand' OR lead_time_days IS NOT NULL
                ),
                ADD CONSTRAINT supplier_product_listing_items_pre_order_needs_eta CHECK (
                    supply_mode <> 'pre_order'
                    OR lead_time_days IS NOT NULL
                    OR expected_availability_at IS NOT NULL
                ),
                ADD CONSTRAINT supplier_product_listing_items_capacity_not_negative CHECK (
                    fulfilment_capacity IS NULL OR fulfilment_capacity >= 0
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listing_items
                DROP CONSTRAINT supplier_product_listing_items_capacity_not_negative,
                DROP CONSTRAINT supplier_product_listing_items_pre_order_needs_eta,
                DROP CONSTRAINT supplier_product_listing_items_on_demand_needs_lead_time,
                DROP CONSTRAINT supplier_product_listing_items_supply_mode_known,
                DROP CONSTRAINT supplier_product_listing_items_quantities_not_negative;
        SQL);

        // Existing NULLs would violate the restored NOT NULL; there are none
        // in practice (this column only becomes nullable in this migration),
        // but a defensive backfill keeps `down()` runnable regardless.
        DB::table('supplier_product_listing_items')->whereNull('available_quantity')->update(['available_quantity' => 0]);

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listing_items
                ADD CONSTRAINT supplier_product_listing_items_quantities_not_negative CHECK (
                    available_quantity >= 0 AND minimum_supply_quantity >= 1
                    AND minimum_supply_quantity <= GREATEST(available_quantity, 1)
                );

            ALTER TABLE supplier_product_listing_items
                ALTER COLUMN available_quantity SET NOT NULL;
        SQL);

        Schema::table('supplier_product_listing_items', function (Blueprint $table) {
            $table->dropColumn(['supply_mode', 'fulfilment_capacity', 'expected_availability_at']);
        });
    }
};
