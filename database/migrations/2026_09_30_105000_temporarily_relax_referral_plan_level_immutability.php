<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Opens the one-time window the currency backfill needs (D4, §25.4.1).
 *
 * {@see \Database\Migrations\2026_09_30_110000_say_which_money_a_referral_level_pays}
 * backfills `currency_code` on every existing level from the plan above it —
 * the very kind of write `referral_plan_levels_written_once` exists to
 * refuse (created by `2026_09_29_110000_create_referral_plans`). On a
 * database with no rows yet the backfill matches nothing and the trigger
 * never fires, so the gap was invisible until a level actually existed to
 * update.
 *
 * A later migration cannot open this window: the migration that fails runs
 * first, and Laravel does not reach anything after a batch that throws. This
 * one runs immediately before it instead, and
 * {@see \Database\Migrations\2026_09_30_115000_restore_referral_plan_level_immutability}
 * closes the window immediately after — the disable never outlives the one
 * statement it exists for, and nothing else runs against this table in
 * between; `artisan migrate` is one connection, one migration at a time.
 *
 * The historical `110000` file is untouched, exactly as committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE referral_plan_levels DISABLE TRIGGER referral_plan_levels_written_once');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE referral_plan_levels ENABLE TRIGGER referral_plan_levels_written_once');
    }
};
