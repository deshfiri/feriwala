<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A cart says which currency its confirmed total is in (D4, P4-8).
 *
 * Every table holding an amount carries `currency_code` beside it, NOT NULL and
 * defaulting to the base currency. The checkout confirmation had put its
 * currency in a nullable `confirmed_currency_code` instead, which the money
 * column convention does not recognise. The cart's own `currency_code` replaces
 * it; a confirmation already recorded keeps the currency it was recorded in,
 * and the rule that a confirmation is written all together or not at all no
 * longer needs to mention currency, because there is always one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE carts DROP CONSTRAINT IF EXISTS carts_confirmation_complete');

        Schema::table('carts', function (Blueprint $table) {
            $table->char('currency_code', 3)->default('BDT')->after('coupon_code');
        });

        DB::statement('UPDATE carts SET currency_code = confirmed_currency_code WHERE confirmed_currency_code IS NOT NULL');

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('confirmed_currency_code');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE carts
                ADD CONSTRAINT carts_confirmation_complete CHECK (
                    (confirmed_at IS NULL AND payment_method IS NULL AND confirmed_fingerprint IS NULL
                        AND confirmed_total_minor IS NULL)
                    OR (confirmed_at IS NOT NULL AND payment_method IS NOT NULL AND confirmed_fingerprint IS NOT NULL
                        AND confirmed_total_minor IS NOT NULL)
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE carts DROP CONSTRAINT IF EXISTS carts_confirmation_complete');

        Schema::table('carts', function (Blueprint $table) {
            $table->char('confirmed_currency_code', 3)->nullable()->after('confirmed_total_minor');
        });

        DB::statement('UPDATE carts SET confirmed_currency_code = currency_code WHERE confirmed_at IS NOT NULL');

        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE carts
                ADD CONSTRAINT carts_confirmation_complete CHECK (
                    (confirmed_at IS NULL AND payment_method IS NULL AND confirmed_fingerprint IS NULL
                        AND confirmed_total_minor IS NULL AND confirmed_currency_code IS NULL)
                    OR (confirmed_at IS NOT NULL AND payment_method IS NOT NULL AND confirmed_fingerprint IS NOT NULL
                        AND confirmed_total_minor IS NOT NULL AND confirmed_currency_code IS NOT NULL)
                )
        SQL);
    }
};
