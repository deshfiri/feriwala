<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The referral hierarchy between business accounts (§25.1, D23, D24, P7-11).
 *
 * **One direct referrer per account**, ever: `referred_account_id` is unique.
 * The owner's referral code is captured on `users` at registration, and this is
 * the account-level relation the multi-level commission engine walks.
 *
 * The database refuses what must never exist, whoever writes the row:
 *
 *   - **self-referral** — a CHECK;
 *   - **a cycle**, an account becoming its own ancestor — a trigger that walks
 *     the new referrer's chain before accepting the row. Every hierarchy write
 *     first takes one transaction-scoped advisory lock, so two writes that
 *     would close a loop between them are serialised and the second sees the
 *     first; hierarchy writes are rare, so one lock for all of them costs
 *     nothing;
 *   - **a change after qualification** — once `locked_at` is set, the referrer
 *     can be neither changed nor removed.
 *
 * Existing registrations are carried across from `users.referred_by_user_id`,
 * account to account.
 */
return new class extends Migration
{
    private const ATTACHED_VIA = ['registration', 'backfill', 'staff'];

    public function up(): void
    {
        Schema::create('account_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referred_account_id')->unique()->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('referrer_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->string('referral_code', 16)->nullable();
            $table->string('attached_via', 16);
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index('referrer_account_id');
        });

        $via = self::quoted(self::ATTACHED_VIA);

        DB::unprepared(<<<SQL
            ALTER TABLE account_referrals
                ADD CONSTRAINT account_referrals_not_self CHECK (referred_account_id <> referrer_account_id),
                ADD CONSTRAINT account_referrals_attached_via_known CHECK (attached_via IN ({$via})),
                ADD CONSTRAINT account_referrals_staff_attachment_is_explained
                    CHECK (attached_via <> 'staff' OR (attached_by IS NOT NULL AND reason IS NOT NULL));

            CREATE OR REPLACE FUNCTION feriwala_referral_hierarchy_guard() RETURNS trigger AS \$\$
            BEGIN
                -- Every hierarchy write, one at a time: two rows that would
                -- close a loop between them cannot both pass the walk below.
                PERFORM pg_advisory_xact_lock(7302024);

                IF TG_OP = 'DELETE' THEN
                    IF OLD.locked_at IS NOT NULL THEN
                        RAISE EXCEPTION 'account_referrals: a referral locked by a qualifying event cannot be removed'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;

                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.locked_at IS NOT NULL
                    AND (NEW.referrer_account_id IS DISTINCT FROM OLD.referrer_account_id
                        OR NEW.referred_account_id IS DISTINCT FROM OLD.referred_account_id
                        OR NEW.locked_at IS NULL) THEN
                    RAISE EXCEPTION 'account_referrals: a referral locked by a qualifying event cannot change'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF EXISTS (
                    WITH RECURSIVE ancestors(account_id, depth) AS (
                        SELECT NEW.referrer_account_id, 1
                        UNION ALL
                        SELECT r.referrer_account_id, a.depth + 1
                        FROM account_referrals r
                        JOIN ancestors a ON r.referred_account_id = a.account_id
                        WHERE a.depth < 100000
                    )
                    SELECT 1 FROM ancestors WHERE account_id = NEW.referred_account_id
                ) THEN
                    RAISE EXCEPTION 'account_referrals: account % would become its own ancestor', NEW.referred_account_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER account_referrals_hierarchy_guard
                BEFORE INSERT OR UPDATE OR DELETE ON account_referrals
                FOR EACH ROW EXECUTE FUNCTION feriwala_referral_hierarchy_guard();
        SQL);

        // What registration already captured, account to account. A referrer
        // who owns no business account has nothing to attach.
        DB::statement(<<<'SQL'
            INSERT INTO account_referrals (referred_account_id, referrer_account_id, referral_code, attached_via, created_at, updated_at)
            SELECT referred.id, referrer.id, referrer_user.referral_code, 'backfill', NOW(), NOW()
            FROM business_accounts referred
            JOIN users owner ON owner.id = referred.owner_id
            JOIN users referrer_user ON referrer_user.id = owner.referred_by_user_id
            JOIN business_accounts referrer ON referrer.owner_id = referrer_user.id
            WHERE referrer.id <> referred.id
            ORDER BY referred.id
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS account_referrals_hierarchy_guard ON account_referrals;
            DROP FUNCTION IF EXISTS feriwala_referral_hierarchy_guard();
        SQL);

        Schema::dropIfExists('account_referrals');
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
