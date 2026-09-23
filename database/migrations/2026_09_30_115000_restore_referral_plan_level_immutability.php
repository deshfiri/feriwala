<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes the window {@see \Database\Migrations\2026_09_30_105000_temporarily_relax_referral_plan_level_immutability}
 * opened (D4, §25.4.1).
 *
 * Runs whether or not the backfill in between found any row to change — a
 * fresh database and a populated one both leave this trigger re-enabled, so
 * `referral_plan_levels` is never left immutable-in-name-only past this
 * migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE referral_plan_levels ENABLE TRIGGER referral_plan_levels_written_once');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE referral_plan_levels DISABLE TRIGGER referral_plan_levels_written_once');
    }
};
