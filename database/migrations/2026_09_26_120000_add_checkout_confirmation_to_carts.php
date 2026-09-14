<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout confirmation on the wholesale cart (§14, P4-8).
 *
 * A confirmation records the payment method chosen, when, the total agreed and a
 * fingerprint of the whole checkout as it was priced at that moment. It holds
 * only while the checkout priced now still has that fingerprint: a change to the
 * cart, a price, a charge, a coupon or an address shows as a confirmation that no
 * longer stands, rather than being charged.
 *
 * Nothing here is a payment, an order or a reservation. The columns are recorded
 * all together or not at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->string('payment_method', 32)->nullable()->after('coupon_code');
            $table->timestamp('confirmed_at')->nullable()->after('payment_method');
            $table->char('confirmed_fingerprint', 64)->nullable()->after('confirmed_at');
            $table->bigInteger('confirmed_total_minor')->nullable()->after('confirmed_fingerprint');
            $table->char('confirmed_currency_code', 3)->nullable()->after('confirmed_total_minor');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE carts
                ADD CONSTRAINT carts_confirmation_complete CHECK (
                    (confirmed_at IS NULL AND payment_method IS NULL AND confirmed_fingerprint IS NULL
                        AND confirmed_total_minor IS NULL AND confirmed_currency_code IS NULL)
                    OR (confirmed_at IS NOT NULL AND payment_method IS NOT NULL AND confirmed_fingerprint IS NOT NULL
                        AND confirmed_total_minor IS NOT NULL AND confirmed_currency_code IS NOT NULL)
                ),
                ADD CONSTRAINT carts_confirmed_total_not_negative CHECK (confirmed_total_minor IS NULL OR confirmed_total_minor >= 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE carts DROP CONSTRAINT IF EXISTS carts_confirmation_complete');
        DB::statement('ALTER TABLE carts DROP CONSTRAINT IF EXISTS carts_confirmed_total_not_negative');

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'confirmed_at', 'confirmed_fingerprint', 'confirmed_total_minor', 'confirmed_currency_code']);
        });
    }
};
