<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freezes how a customer order's delivery charge was arrived at (D37), beside
 * the charge itself (`orders.delivery`, already there and already locked).
 *
 *   - `delivery_area`         — `inside_dhaka` or `outside_dhaka`;
 *   - `delivery_district_id`  — the canonical BdLocation district it was
 *                               resolved from;
 *   - `delivery_fee_rule_id`  — the dated fee rule (the pricing version) that
 *                               priced it, whose amount is the configured rate;
 *   - `delivery_tax`          — the tax assessed on the delivery charge, and
 *   - `delivery_tax_mode`     — whether it was inside the charge or added to it.
 *
 * All or nothing, and nullable: a wholesale order and every order placed before
 * this record only the charge they were placed with, and nothing is guessed
 * for them. Locked by their own trigger, so a later change to a rate can never
 * rewrite an order already placed (the pattern `orders_account_type_locked`
 * follows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('delivery_area', 16)->nullable()->after('delivery');
            $table->foreignId('delivery_district_id')->nullable()->after('delivery_area')
                ->constrained('bd_locations')->restrictOnDelete();
            $table->foreignId('delivery_fee_rule_id')->nullable()->after('delivery_district_id')
                ->constrained('fee_rules')->restrictOnDelete();
            $table->decimal('delivery_tax', 19, 2)->nullable()->after('delivery_fee_rule_id');
            $table->string('delivery_tax_mode', 16)->nullable()->after('delivery_tax');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_delivery_area_known CHECK (
                    delivery_area IS NULL OR delivery_area IN ('inside_dhaka', 'outside_dhaka')
                ),
                ADD CONSTRAINT orders_delivery_tax_mode_known CHECK (
                    delivery_tax_mode IS NULL OR delivery_tax_mode IN ('exclusive', 'inclusive')
                ),
                ADD CONSTRAINT orders_delivery_tax_not_negative CHECK (delivery_tax IS NULL OR delivery_tax >= 0),
                -- The snapshot is whole or absent: an area always with the
                -- district and the rule that priced it.
                ADD CONSTRAINT orders_delivery_snapshot_whole CHECK (
                    (delivery_area IS NULL AND delivery_district_id IS NULL AND delivery_fee_rule_id IS NULL)
                    OR (delivery_area IS NOT NULL AND delivery_district_id IS NOT NULL AND delivery_fee_rule_id IS NOT NULL)
                );

            CREATE TRIGGER orders_delivery_snapshot_locked
                BEFORE UPDATE OF delivery_area, delivery_district_id, delivery_fee_rule_id, delivery_tax, delivery_tax_mode ON orders
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'delivery_area', 'delivery_district_id', 'delivery_fee_rule_id', 'delivery_tax', 'delivery_tax_mode'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS orders_delivery_snapshot_locked ON orders;');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_fee_rule_id');
            $table->dropConstrainedForeignId('delivery_district_id');
            $table->dropColumn(['delivery_area', 'delivery_tax', 'delivery_tax_mode']);
        });
    }
};
