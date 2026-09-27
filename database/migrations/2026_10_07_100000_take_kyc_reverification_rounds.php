<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tells a re-verification round apart from an onboarding one, and records what
 * it costs the business while it is open (§7.2, §7.4).
 *
 * `kyc_submissions` already carried everything a round needs — the round
 * number, the requesting staff member, the reason, the instructions, the
 * deadline, and a UNIQUE (business_account_id, round) that makes history
 * immutable by construction. What it could not say is **why** the round
 * exists. An onboarding round, a correction and a re-verification look
 * identical on the wire and mean entirely different things to the business
 * reading them; only the last can carry consequences, because only the last
 * happens to an account that is already trading.
 *
 * So: a `purpose`, and the consequences the member of staff chose for this
 * case. §7.4 offers consequences rather than requiring them, and offers
 * several — which means they belong to the case, not to one global setting.
 * The existing `kyc.overdue_restricts_active_account` setting stays exactly as
 * it is and keeps governing the rounds that carry no explicit choice.
 *
 * Additive throughout. Every existing row reads as an onboarding round with no
 * consequences, which is the behaviour that was in force before this ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->string('purpose', 24)->default('onboarding');

            // The chosen consequence set, as an array of KycConsequence
            // values. Null for a round that carries none.
            $table->jsonb('consequences')->nullable();

            /*
             * Stamped once, by whichever worker wins the deadline claim, so
             * consequences are applied exactly once however many times the
             * sweep runs. The `KycDeadlineEvent` claim already guarantees this
             * for the existing enforcement; this is the same guarantee made
             * visible on the round a reviewer is looking at.
             */
            $table->timestamp('consequences_applied_at')->nullable();

            // Withdrawing a request that was never answered (§7.2). The round
            // stays in history: it is evidence that we asked.
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_submissions
                ADD CONSTRAINT kyc_submissions_purpose_known CHECK (
                    purpose IN ('onboarding', 'correction', 'reverification')
                ),

                /*
                 * A cancellation is whole or it did not happen. A cancelled
                 * round with no reason is one nobody can account for later,
                 * and §7.2 makes the reason the point of recording it at all.
                 */
                ADD CONSTRAINT kyc_submissions_cancellation_is_whole CHECK (
                    (cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)
                    OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL
                        AND length(btrim(cancellation_reason)) > 0)
                ),

                /*
                 * Only a re-verification may carry consequences. An onboarding
                 * round belongs to an account that is not trading, so there is
                 * nothing for a consequence to restrict — and a row claiming
                 * otherwise would make the restriction query answer for an
                 * account it should never reach.
                 */
                ADD CONSTRAINT kyc_submissions_only_reverification_restricts CHECK (
                    consequences IS NULL OR purpose = 'reverification'
                ),

                /*
                 * Consequences hang off a deadline, so a round that carries
                 * them must state one -- "suspend after the deadline" is
                 * meaningless without a deadline, and a business whose trading
                 * is restricted is entitled to know by when it must answer.
                 *
                 * Deliberately narrower than "every re-verification needs a
                 * deadline". §7.4 makes deadlines opt-in and `kyc.deadline_days`
                 * may be unset, in which case asking a business to re-verify
                 * with no fixed date and no consequences is a legitimate,
                 * gentle request. The staff flow that chooses consequences
                 * requires a deadline in its own validation; this is the
                 * invariant the data itself cannot do without.
                 */
                ADD CONSTRAINT kyc_submissions_consequences_need_a_deadline CHECK (
                    consequences IS NULL OR deadline_at IS NOT NULL
                );

            /*
             * **One open re-verification per account.**
             *
             * `RequestKycUpdate` already refuses a second round while one is
             * editable or awaiting review, under an account row lock, and the
             * UNIQUE (business_account_id, round) is the backstop behind that.
             * This is narrower and says the thing directly: whatever else is
             * in flight, an account has at most one live re-verification case
             * -- so the restriction query can never find two disagreeing about
             * what this business may do.
             *
             * "Open" is the honest definition: not yet approved or rejected,
             * and not withdrawn.
             */
            CREATE UNIQUE INDEX kyc_submissions_one_open_reverification
                ON kyc_submissions (business_account_id)
                WHERE purpose = 'reverification'
                  AND cancelled_at IS NULL
                  AND status NOT IN ('approved', 'rejected');

            -- The sweep reads exactly this.
            CREATE INDEX kyc_submissions_open_reverifications
                ON kyc_submissions (deadline_at)
                WHERE purpose = 'reverification'
                  AND cancelled_at IS NULL
                  AND status NOT IN ('approved', 'rejected');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS kyc_submissions_open_reverifications;
            DROP INDEX IF EXISTS kyc_submissions_one_open_reverification;

            ALTER TABLE kyc_submissions
                DROP CONSTRAINT IF EXISTS kyc_submissions_consequences_need_a_deadline,
                DROP CONSTRAINT IF EXISTS kyc_submissions_only_reverification_restricts,
                DROP CONSTRAINT IF EXISTS kyc_submissions_cancellation_is_whole,
                DROP CONSTRAINT IF EXISTS kyc_submissions_purpose_known;
        SQL);

        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'purpose', 'consequences', 'consequences_applied_at',
                'cancelled_at', 'cancellation_reason',
            ]);
        });
    }
};
