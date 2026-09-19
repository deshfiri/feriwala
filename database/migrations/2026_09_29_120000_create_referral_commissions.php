<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualifying events and the commissions they calculate (D24, P7-42, P7-43).
 *
 * **One event per trigger and subject, ever** — one activation per account —
 * so a retry, a duplicate callback or a second activation finds the event
 * already there and pays nothing again.
 *
 * A commission row is the **outbox**: written in the qualifying event's own
 * transaction, with the beneficiary, the level, the plan version, a snapshot of
 * the rule that was applied, the base and the amount. It reaches the wallet
 * later, through `WalletService`, with an idempotency key of its own, and its
 * wallet transaction is recorded on it. One row per event and level, ever.
 *
 * Neither table forgets: the terms of a row are fixed once written, only its
 * progress moves, and nothing is deleted.
 */
return new class extends Migration
{
    private const TRIGGERS = ['account_activation'];

    private const EVENT_STATUSES = ['recorded', 'reversed'];

    private const STATUSES = ['skipped', 'pending', 'paid', 'cancelled', 'reversed', 'reversal_owed'];

    private const KINDS = ['level', 'joining'];

    private const CAUSES = ['refund', 'chargeback', 'fraud', 'activation_rollback', 'manual', 'beneficiary_closed'];

    private const EVENT_LOCKED = [
        'public_id', 'trigger_event', 'source_account_id', 'subject_type', 'subject_id', 'payment_id',
        'referral_plan_id', 'currency_code', 'commission_base_minor', 'chain', 'occurred_at', 'created_at',
    ];

    private const COMMISSION_LOCKED = [
        'public_id', 'referral_qualifying_event_id', 'referral_plan_id', 'beneficiary_account_id',
        'source_account_id', 'level', 'kind', 'rule_snapshot', 'commission_base_minor', 'amount_minor',
        'currency_code', 'capped', 'skip_reason', 'available_at', 'created_at',
    ];

    public function up(): void
    {
        Schema::create('referral_qualifying_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('trigger_event', 32);
            $table->foreignId('source_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->foreignId('referral_plan_id')->constrained('referral_plans')->restrictOnDelete();
            $table->char('currency_code', 3)->default('BDT');
            $table->bigInteger('commission_base_minor');
            $table->jsonb('chain');
            $table->string('status', 16)->default('recorded');
            $table->timestampTz('occurred_at');
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            // One event per trigger and subject, ever.
            $table->unique(['trigger_event', 'subject_type', 'subject_id']);
            $table->index('source_account_id');
        });

        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('referral_qualifying_event_id')->constrained('referral_qualifying_events')->restrictOnDelete();
            $table->foreignId('referral_plan_id')->constrained('referral_plans')->restrictOnDelete();
            $table->foreignId('beneficiary_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('source_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('kind', 16);
            $table->jsonb('rule_snapshot');
            $table->bigInteger('commission_base_minor');
            $table->bigInteger('amount_minor');
            $table->char('currency_code', 3)->default('BDT');
            $table->boolean('capped')->default(false);
            $table->string('status', 16);
            $table->string('skip_reason', 40)->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('paid_at')->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->timestampTz('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->string('reversal_cause', 32)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('reversal_wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->timestamps();

            // One commission per event and level, ever; level 0 is the joining reward.
            $table->unique(['referral_qualifying_event_id', 'level']);
            $table->index(['beneficiary_account_id', 'created_at']);
            $table->index(['status', 'available_at']);
        });

        $triggers = self::quoted(self::TRIGGERS);
        $eventStatuses = self::quoted(self::EVENT_STATUSES);
        $statuses = self::quoted(self::STATUSES);
        $kinds = self::quoted(self::KINDS);
        $causes = self::quoted(self::CAUSES);
        $eventLocked = self::quoted(self::EVENT_LOCKED);
        $commissionLocked = self::quoted(self::COMMISSION_LOCKED);

        DB::unprepared(<<<SQL
            ALTER TABLE referral_qualifying_events
                ADD CONSTRAINT referral_events_trigger_known CHECK (trigger_event IN ({$triggers})),
                ADD CONSTRAINT referral_events_status_known CHECK (status IN ({$eventStatuses})),
                ADD CONSTRAINT referral_events_base_not_negative CHECK (commission_base_minor >= 0),
                ADD CONSTRAINT referral_events_chain_is_a_list CHECK (jsonb_typeof(chain) = 'array'),
                ADD CONSTRAINT referral_events_reversal_is_recorded CHECK (
                    status <> 'reversed' OR (reversed_at IS NOT NULL AND reversal_reason IS NOT NULL)
                );

            ALTER TABLE referral_commissions
                ADD CONSTRAINT referral_commissions_status_known CHECK (status IN ({$statuses})),
                ADD CONSTRAINT referral_commissions_kind_known CHECK (kind IN ({$kinds})),
                ADD CONSTRAINT referral_commissions_cause_known CHECK (reversal_cause IS NULL OR reversal_cause IN ({$causes})),
                ADD CONSTRAINT referral_commissions_kind_matches_level CHECK ((kind = 'joining') = (level = 0)),
                ADD CONSTRAINT referral_commissions_amount_within_base CHECK (
                    amount_minor >= 0 AND commission_base_minor >= 0 AND amount_minor <= commission_base_minor
                ),
                ADD CONSTRAINT referral_commissions_skip_is_explained CHECK (
                    (status = 'skipped') = (skip_reason IS NOT NULL) AND (status <> 'skipped' OR amount_minor = 0)
                ),
                ADD CONSTRAINT referral_commissions_payable_is_positive CHECK (status = 'skipped' OR amount_minor > 0),
                -- Paid, and every state after paying, carries the credit that paid it.
                ADD CONSTRAINT referral_commissions_payment_is_recorded CHECK (
                    (status IN ('paid', 'reversed', 'reversal_owed')) = (wallet_transaction_id IS NOT NULL AND paid_at IS NOT NULL)
                ),
                ADD CONSTRAINT referral_commissions_reversal_is_recorded CHECK (
                    status NOT IN ('cancelled', 'reversed', 'reversal_owed')
                    OR (reversed_at IS NOT NULL AND reversal_reason IS NOT NULL AND reversal_cause IS NOT NULL)
                ),
                -- Reversed means the compensating debit was posted; owed means it was not yet.
                ADD CONSTRAINT referral_commissions_reversal_posted CHECK (
                    (status = 'reversed') = (reversal_wallet_transaction_id IS NOT NULL)
                );

            CREATE TRIGGER referral_qualifying_events_locked_columns
                BEFORE UPDATE ON referral_qualifying_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$eventLocked});

            CREATE TRIGGER referral_commissions_locked_columns
                BEFORE UPDATE ON referral_commissions
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked({$commissionLocked});

            CREATE OR REPLACE FUNCTION feriwala_referral_record_is_kept() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION '% rows are the financial record and are never deleted', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER referral_qualifying_events_kept
                BEFORE DELETE ON referral_qualifying_events
                FOR EACH ROW EXECUTE FUNCTION feriwala_referral_record_is_kept();

            CREATE TRIGGER referral_commissions_kept
                BEFORE DELETE ON referral_commissions
                FOR EACH ROW EXECUTE FUNCTION feriwala_referral_record_is_kept();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS referral_commissions_kept ON referral_commissions;
            DROP TRIGGER IF EXISTS referral_qualifying_events_kept ON referral_qualifying_events;
            DROP TRIGGER IF EXISTS referral_commissions_locked_columns ON referral_commissions;
            DROP TRIGGER IF EXISTS referral_qualifying_events_locked_columns ON referral_qualifying_events;
            DROP FUNCTION IF EXISTS feriwala_referral_record_is_kept();
        SQL);

        Schema::dropIfExists('referral_commissions');
        Schema::dropIfExists('referral_qualifying_events');
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
