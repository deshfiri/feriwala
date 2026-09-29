<?php

use App\Domain\Wallet\WalletService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Client/Partner `BusinessAccount`'s request to withdraw money from its
 * wallet (§27, D25's Client/Partner side; Withdrawal Integration batch),
 * mirroring `supplier_withdrawals`' own shape for a different owner and a
 * different underlying reservation primitive.
 *
 * A Supplier's withdrawal reserves through its own `SupplierWallet` bucket
 * system directly. A Client/Partner account's wallet is the shared,
 * generic `Wallet`/`WalletService` ({@see WalletService}),
 * whose reservation primitive is a `WalletTransaction` "claim" row (status
 * `Pending` while reserved, `Settled` once captured/paid, `Cancelled` once
 * released) — `wallet_transaction_id` names that claim, so paying or
 * rejecting this withdrawal is exactly one call to
 * `WalletService::capture()`/`release()` on the row it already names,
 * never a bucket recomputed by hand.
 *
 * `payout_snapshot` is frozen at request time from the `PayoutMethod` it
 * names, so an edit or an archive of that method afterwards can never
 * change what this withdrawal says it was paid to — identical to the
 * Supplier side.
 *
 * Identity, amount, currency, the payout snapshot, the wallet transaction
 * and the idempotency key are locked by trigger the moment the row exists;
 * only the workflow columns move, through the same status-history pattern
 * every other lifecycle in this domain uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('business_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('payout_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->constrained()->restrictOnDelete();
            $table->jsonb('payout_snapshot');

            $table->decimal('amount', 19, 2);
            $table->char('currency_code', 3);

            $table->string('status', 16);
            $table->timestamp('requested_at');

            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('processed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('external_reference', 120)->nullable();
            $table->text('failure_reason')->nullable();

            $table->string('idempotency_key', 191)->unique();

            $table->timestamps();

            $table->index(['business_account_id', 'status']);
            $table->index(['status', 'requested_at']);
        });

        Schema::create('account_withdrawal_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_withdrawal_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['account_withdrawal_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE account_withdrawals
                ADD CONSTRAINT account_withdrawals_amount_positive CHECK (amount > 0),
                ADD CONSTRAINT account_withdrawals_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT account_withdrawals_status_known CHECK (
                    status IN ('requested', 'under_review', 'approved', 'rejected', 'processing', 'paid', 'failed', 'reversed')
                ),
                ADD CONSTRAINT account_withdrawals_paid_is_complete CHECK (
                    (status = 'paid') = (paid_at IS NOT NULL AND external_reference IS NOT NULL)
                );

            CREATE TRIGGER account_withdrawals_locked_columns
                BEFORE UPDATE OF
                    public_id, reference, business_account_id, wallet_id, payout_method_id,
                    wallet_transaction_id, payout_snapshot, amount, currency_code, idempotency_key
                ON account_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'business_account_id', 'wallet_id', 'payout_method_id',
                    'wallet_transaction_id', 'payout_snapshot', 'amount', 'currency_code', 'idempotency_key'
                );

            CREATE OR REPLACE FUNCTION feriwala_account_withdrawal_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'an account withdrawal is never deleted'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER account_withdrawals_never_deleted
                BEFORE DELETE ON account_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_account_withdrawal_never_deleted();

            ALTER TABLE account_withdrawal_status_history
                ADD CONSTRAINT account_withdrawal_status_history_source_known CHECK (
                    source IN ('account', 'staff', 'system', 'scheduler', 'payment_gateway')
                ),
                ADD CONSTRAINT account_withdrawal_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER account_withdrawal_status_history_no_update
                BEFORE UPDATE ON account_withdrawal_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER account_withdrawal_status_history_no_delete
                BEFORE DELETE ON account_withdrawal_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            -- A withdrawal's currency must be its wallet's own (D4).
            CREATE OR REPLACE FUNCTION feriwala_account_withdrawal_currency_matches_wallet() RETURNS trigger AS $$
            DECLARE
                wallet_currency char(3);
            BEGIN
                SELECT currency_code INTO wallet_currency FROM wallets WHERE id = NEW.wallet_id;

                IF wallet_currency IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION 'an account withdrawal must use its wallet''s own currency'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER account_withdrawals_currency_matches_wallet
                BEFORE INSERT ON account_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_account_withdrawal_currency_matches_wallet();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS account_withdrawals_currency_matches_wallet ON account_withdrawals;
            DROP FUNCTION IF EXISTS feriwala_account_withdrawal_currency_matches_wallet();
            DROP TRIGGER IF EXISTS account_withdrawal_status_history_no_delete ON account_withdrawal_status_history;
            DROP TRIGGER IF EXISTS account_withdrawal_status_history_no_update ON account_withdrawal_status_history;
            DROP TRIGGER IF EXISTS account_withdrawals_never_deleted ON account_withdrawals;
            DROP FUNCTION IF EXISTS feriwala_account_withdrawal_never_deleted();
            DROP TRIGGER IF EXISTS account_withdrawals_locked_columns ON account_withdrawals;
        SQL);

        Schema::dropIfExists('account_withdrawal_status_history');
        Schema::dropIfExists('account_withdrawals');
    }
};
