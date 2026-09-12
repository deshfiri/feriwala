<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed a deposit requirement, from what, to what, and why (§24.1).
 *
 * The general audit log already records that somebody acted. This records what
 * the figures **were** — and that is a different question. A deposit rule is the
 * reason an account is restricted or allowed to trade, so "the minimum balance
 * was two thousand until the 3rd, then somebody raised it to five" has to be
 * answerable from one place, months later, without replaying an event stream.
 *
 * Append-only, enforced in the database. A record of a change that can itself be
 * changed answers nothing.
 *
 * The before and after are whole snapshots rather than a diff. A diff is only
 * readable next to the row it applies to, and the row will have moved on; a
 * snapshot still says what the rule was on the day somebody has a question
 * about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_rule_changes', function (Blueprint $table) {
            $table->id();

            /*
             * A rule is closed, never deleted, so this should never come up.
             * `restrictOnDelete` says what happens if it does: a requirement
             * somebody was held to is not removable, and its history is the
             * reason.
             */
            $table->foreignId('deposit_rule_id')->constrained()->restrictOnDelete();

            // `created`, `closed`, `deactivated` — what was done to it.
            $table->string('action', 30);

            /*
             * The figures either side of the change. Null `before` means the
             * rule did not exist yet: a creation has no previous state, which is
             * not the same as one whose previous state was empty.
             */
            $table->json('before')->nullable();
            $table->json('after');

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();

            // The date the change takes effect, which is not the date it was
            // made: §24.1 lets a deposit be decided today and begin next month.
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();

            $table->timestamp('created_at');

            $table->index(['deposit_rule_id', 'id']);
            $table->index('created_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER deposit_rule_changes_no_update
                BEFORE UPDATE ON deposit_rule_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER deposit_rule_changes_no_delete
                BEFORE DELETE ON deposit_rule_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS deposit_rule_changes_no_update ON deposit_rule_changes;
            DROP TRIGGER IF EXISTS deposit_rule_changes_no_delete ON deposit_rule_changes;
        SQL);

        Schema::dropIfExists('deposit_rule_changes');
    }
};
