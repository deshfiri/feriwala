<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A referral level says which money it pays (D4, §25.4.1).
 *
 * Every table holding an amount names its currency beside it, so that the day
 * the platform is not BDT-only there is no row whose meaning has to be guessed.
 * A level's amounts — a fixed reward and a cap — were the exception, reading
 * their currency from the plan above them.
 *
 * It is still the plan's currency: the column is filled from the plan it
 * belongs to, and the database refuses a level that claims another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_plan_levels', function (Blueprint $table) {
            $table->char('currency_code', 3)->default('BDT')->after('level');
        });

        DB::unprepared(<<<'SQL'
            UPDATE referral_plan_levels
               SET currency_code = referral_plans.currency_code
              FROM referral_plans
             WHERE referral_plans.id = referral_plan_levels.referral_plan_id;

            CREATE OR REPLACE FUNCTION feriwala_referral_level_pays_the_plan_s_money() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM referral_plans
                    WHERE id = NEW.referral_plan_id AND currency_code = NEW.currency_code
                ) THEN
                    RAISE EXCEPTION 'a referral level pays the currency of its plan'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER referral_plan_levels_pay_the_plan_s_money
                BEFORE INSERT ON referral_plan_levels
                FOR EACH ROW EXECUTE FUNCTION feriwala_referral_level_pays_the_plan_s_money();

            CREATE TRIGGER referral_plan_levels_currency_is_locked
                BEFORE UPDATE OF currency_code ON referral_plan_levels
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('currency_code');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS referral_plan_levels_currency_is_locked ON referral_plan_levels;
            DROP TRIGGER IF EXISTS referral_plan_levels_pay_the_plan_s_money ON referral_plan_levels;
            DROP FUNCTION IF EXISTS feriwala_referral_level_pays_the_plan_s_money();
        SQL);

        Schema::table('referral_plan_levels', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
