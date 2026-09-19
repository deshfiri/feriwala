<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-level referral plans (§25.4, §25.4.1, D24, P7-12).
 *
 * A **plan version** is opened and closed, never edited: what a version paid,
 * it paid under exactly these terms, and a commission calculated last month
 * must be explainable by the row it names. Changing the depth, a level's
 * reward or who qualifies is a new version from a date.
 *
 * `max_depth` is data. A version carries one level row for every level from 1
 * to its depth — the application checks the set is complete when it opens a
 * version, and the level rows can never be changed afterwards.
 *
 * Amounts are integer minor units; percentages are integer basis points
 * (1 000 = 10%). Neither table can hold a float.
 */
return new class extends Migration
{
    private const TRIGGERS = ['account_activation'];

    private const BASES = ['activation_fees', 'package_fee', 'registration_fee'];

    private const REWARD_TYPES = ['fixed', 'percentage'];

    /** Everything but the closing of a version, fixed once written. */
    private const LOCKED = [
        'public_id', 'package_id', 'trigger_event', 'commission_base', 'max_depth', 'currency_code',
        'joining_reward_type', 'joining_reward_amount_minor', 'joining_reward_rate_bps', 'joining_reward_cap_minor',
        'holding_days', 'minimum_qualifying_payment_minor',
        'qualifies_suspended', 'qualifies_restricted', 'qualifies_package_lapsed', 'qualifies_not_active',
        'effective_from', 'reason', 'opened_by', 'created_at',
    ];

    public function up(): void
    {
        Schema::create('referral_plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('package_id')->nullable()->constrained('packages')->restrictOnDelete();
            $table->string('trigger_event', 32);
            $table->string('commission_base', 32);
            $table->unsignedSmallInteger('max_depth');
            $table->char('currency_code', 3)->default('BDT');

            $table->string('joining_reward_type', 16)->nullable();
            $table->bigInteger('joining_reward_amount_minor')->nullable();
            $table->integer('joining_reward_rate_bps')->nullable();
            $table->bigInteger('joining_reward_cap_minor')->nullable();

            $table->unsignedSmallInteger('holding_days')->default(0);
            $table->bigInteger('minimum_qualifying_payment_minor')->default(0);

            // Which beneficiaries a version still pays (D24).
            $table->boolean('qualifies_suspended')->default(false);
            $table->boolean('qualifies_restricted')->default(false);
            $table->boolean('qualifies_package_lapsed')->default(false);
            $table->boolean('qualifies_not_active')->default(false);

            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->text('reason');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_reason')->nullable();
            $table->timestamps();

            $table->index(['package_id', 'effective_from']);
        });

        Schema::create('referral_plan_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_plan_id')->constrained('referral_plans')->restrictOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('reward_type', 16);
            $table->bigInteger('amount_minor')->nullable();
            $table->integer('rate_bps')->nullable();
            $table->bigInteger('cap_minor')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('required_package_ids')->nullable();
            $table->unsignedSmallInteger('min_active_direct_referrals')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['referral_plan_id', 'level']);
        });

        $triggers = self::quoted(self::TRIGGERS);
        $bases = self::quoted(self::BASES);
        $types = self::quoted(self::REWARD_TYPES);
        $locked = self::quoted(self::LOCKED);

        DB::unprepared(<<<SQL
            ALTER TABLE referral_plans
                ADD CONSTRAINT referral_plans_trigger_known CHECK (trigger_event IN ({$triggers})),
                ADD CONSTRAINT referral_plans_base_known CHECK (commission_base IN ({$bases})),
                ADD CONSTRAINT referral_plans_depth_bounded CHECK (max_depth BETWEEN 1 AND 100),
                ADD CONSTRAINT referral_plans_holding_bounded CHECK (holding_days BETWEEN 0 AND 365),
                ADD CONSTRAINT referral_plans_minimum_not_negative CHECK (minimum_qualifying_payment_minor >= 0),
                ADD CONSTRAINT referral_plans_window CHECK (effective_to IS NULL OR effective_to >= effective_from),
                ADD CONSTRAINT referral_plans_closing_is_recorded CHECK (
                    (closed_at IS NULL AND closed_by IS NULL AND close_reason IS NULL)
                    OR (closed_at IS NOT NULL AND closed_by IS NOT NULL AND close_reason IS NOT NULL AND effective_to IS NOT NULL)
                ),
                ADD CONSTRAINT referral_plans_joining_reward_is_whole CHECK (
                    (joining_reward_type IS NULL AND joining_reward_amount_minor IS NULL AND joining_reward_rate_bps IS NULL AND joining_reward_cap_minor IS NULL)
                    OR (joining_reward_type = 'fixed' AND joining_reward_amount_minor > 0 AND joining_reward_rate_bps IS NULL)
                    OR (joining_reward_type = 'percentage' AND joining_reward_rate_bps BETWEEN 1 AND 10000 AND joining_reward_amount_minor IS NULL)
                ),
                ADD CONSTRAINT referral_plans_joining_cap_positive CHECK (joining_reward_cap_minor IS NULL OR joining_reward_cap_minor > 0);

            ALTER TABLE referral_plan_levels
                ADD CONSTRAINT referral_plan_levels_level_positive CHECK (level >= 1),
                ADD CONSTRAINT referral_plan_levels_type_known CHECK (reward_type IN ({$types})),
                ADD CONSTRAINT referral_plan_levels_reward_is_whole CHECK (
                    (reward_type = 'fixed' AND amount_minor > 0 AND rate_bps IS NULL)
                    OR (reward_type = 'percentage' AND rate_bps BETWEEN 1 AND 10000 AND amount_minor IS NULL)
                ),
                ADD CONSTRAINT referral_plan_levels_cap_positive CHECK (cap_minor IS NULL OR cap_minor > 0),
                ADD CONSTRAINT referral_plan_levels_packages_are_a_list CHECK (
                    required_package_ids IS NULL OR jsonb_typeof(required_package_ids) = 'array'
                );

            CREATE TRIGGER referral_plans_locked_columns
                BEFORE UPDATE ON referral_plans
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$locked});

            CREATE OR REPLACE FUNCTION feriwala_referral_rule_is_kept() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION '% rows are kept: a plan version is closed, never deleted', TG_TABLE_NAME
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RAISE EXCEPTION '% rows are written once: change a level by opening a new plan version', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER referral_plans_kept
                BEFORE DELETE ON referral_plans
                FOR EACH ROW EXECUTE FUNCTION feriwala_referral_rule_is_kept();

            CREATE TRIGGER referral_plan_levels_written_once
                BEFORE UPDATE OR DELETE ON referral_plan_levels
                FOR EACH ROW EXECUTE FUNCTION feriwala_referral_rule_is_kept();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS referral_plan_levels_written_once ON referral_plan_levels;
            DROP TRIGGER IF EXISTS referral_plans_kept ON referral_plans;
            DROP TRIGGER IF EXISTS referral_plans_locked_columns ON referral_plans;
            DROP FUNCTION IF EXISTS feriwala_referral_rule_is_kept();
        SQL);

        Schema::dropIfExists('referral_plan_levels');
        Schema::dropIfExists('referral_plans');
    }

    /**
     * @param  list<literal-string>  $values
     * @return literal-string
     */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
    }
};
