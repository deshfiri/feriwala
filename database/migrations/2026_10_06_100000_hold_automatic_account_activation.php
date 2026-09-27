<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An explicit hold that keeps one account off the automatic activation path
 * (D27).
 *
 * Activation becomes automatic once KYC is approved and the activation payment
 * has settled, so "a person looks at this one first" needs somewhere to live
 * that is not simply the absence of a decision. This is that place: a hold is
 * set deliberately, by a named member of staff, with a reason, and it stops
 * **only** the automatic path. A held account still reaches the approval gate
 * and can still be activated by hand by someone with the authority — the hold
 * is a reason to look, not a wall.
 *
 * Deliberately not part of `ActivationRequirements`. Those are the conditions
 * §5.1 requires of the account itself, and a reviewer must be able to satisfy
 * themselves and activate a held account without first having to unpick the
 * requirement list. Keeping the hold outside them is what makes that possible.
 *
 * Additive columns on an existing table: no historical migration is rewritten,
 * and every existing account reads as "not held", which is the behaviour that
 * was in force before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_accounts', function (Blueprint $table) {
            $table->text('activation_hold_reason')->nullable();
            $table->timestamp('activation_held_at')->nullable();
            $table->foreignId('activation_held_by')->nullable()
                ->constrained('users')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            /*
             * A hold is all three columns or none of them. A reason with no
             * timestamp would never be found by the queue; a timestamp with no
             * reason would stop an activation without telling anybody why, and
             * "held, cause unknown" is exactly the state nobody can clear.
             */
            ALTER TABLE business_accounts
                ADD CONSTRAINT business_accounts_activation_hold_is_whole CHECK (
                    (activation_hold_reason IS NULL AND activation_held_at IS NULL AND activation_held_by IS NULL)
                    OR (length(btrim(activation_hold_reason)) > 0 AND activation_held_at IS NOT NULL AND activation_held_by IS NOT NULL)
                );

            -- The held queue is small and read on every review screen.
            CREATE INDEX business_accounts_activation_held
                ON business_accounts (activation_held_at)
                WHERE activation_held_at IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS business_accounts_activation_held;
            ALTER TABLE business_accounts DROP CONSTRAINT IF EXISTS business_accounts_activation_hold_is_whole;
        SQL);

        Schema::table('business_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activation_held_by');
            $table->dropColumn(['activation_hold_reason', 'activation_held_at']);
        });
    }
};
