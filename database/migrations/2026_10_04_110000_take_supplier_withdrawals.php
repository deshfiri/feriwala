<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier payout methods and withdrawal requests (D25, P13-24).
 *
 * A payout method's sensitive fields are encrypted at rest by the model
 * (Laravel's own `encrypted:array` cast, the same mechanism `suppliers.
 * payout_details` already uses); `last_four` is stored in the clear
 * deliberately, so a masked display never has to touch the encrypted column.
 * A method is never deleted, only archived — a withdrawal already made
 * against it keeps its own frozen `payout_snapshot`, so archiving or editing
 * the method afterwards changes nothing about a past withdrawal.
 *
 * `supplier_withdrawals` is the one immutable record of a request: its
 * identity, amount, currency, payout snapshot and idempotency key are locked
 * by trigger the moment it exists, and only the workflow columns — status
 * and its timestamps — move, through the same status-history pattern every
 * other Supplier lifecycle in this domain uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payout_methods', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);
            $table->string('label', 80);
            $table->text('details')->nullable();
            $table->string('last_four', 4);

            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_payout_method_id')->constrained()->restrictOnDelete();
            $table->jsonb('payout_snapshot');

            $table->bigInteger('amount_minor');
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

            $table->index(['supplier_id', 'status']);
            $table->index(['status', 'requested_at']);
        });

        Schema::create('supplier_withdrawal_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_withdrawal_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 16)->nullable();
            $table->string('new_status', 16);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['supplier_withdrawal_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_payout_methods
                ADD CONSTRAINT supplier_payout_methods_type_known CHECK (
                    type IN ('bank_account', 'bkash', 'nagad')
                ),
                ADD CONSTRAINT supplier_payout_methods_status_known CHECK (
                    status IN ('active', 'archived')
                );

            -- Never deleted (D25's own pattern for identity rows) — a
            -- withdrawal's payout_snapshot is what keeps a past request
            -- readable however the method is edited or archived afterwards.
            CREATE OR REPLACE FUNCTION feriwala_supplier_payout_methods_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a Supplier payout method is never deleted: archive it instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payout_methods_never_deleted
                BEFORE DELETE ON supplier_payout_methods
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payout_methods_never_deleted();

            ALTER TABLE supplier_withdrawals
                ADD CONSTRAINT supplier_withdrawals_amount_positive CHECK (amount_minor > 0),
                ADD CONSTRAINT supplier_withdrawals_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT supplier_withdrawals_status_known CHECK (
                    status IN ('requested', 'under_review', 'approved', 'rejected', 'processing', 'paid', 'failed', 'reversed')
                ),
                -- Paid means paid: both facts recorded together, or neither.
                ADD CONSTRAINT supplier_withdrawals_paid_is_complete CHECK (
                    (status = 'paid') = (paid_at IS NOT NULL AND external_reference IS NOT NULL)
                );

            CREATE TRIGGER supplier_withdrawals_locked_columns
                BEFORE UPDATE OF
                    public_id, reference, supplier_id, supplier_wallet_id, supplier_payout_method_id,
                    payout_snapshot, amount_minor, currency_code, idempotency_key
                ON supplier_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'supplier_wallet_id', 'supplier_payout_method_id',
                    'payout_snapshot', 'amount_minor', 'currency_code', 'idempotency_key'
                );

            CREATE OR REPLACE FUNCTION feriwala_supplier_withdrawal_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a Supplier withdrawal is never deleted'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_withdrawals_never_deleted
                BEFORE DELETE ON supplier_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_withdrawal_never_deleted();

            ALTER TABLE supplier_withdrawal_status_history
                ADD CONSTRAINT supplier_withdrawal_status_history_source_known CHECK (
                    source IN ('supplier', 'staff', 'system', 'scheduler', 'payment_gateway')
                ),
                ADD CONSTRAINT supplier_withdrawal_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER supplier_withdrawal_status_history_no_update
                BEFORE UPDATE ON supplier_withdrawal_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER supplier_withdrawal_status_history_no_delete
                BEFORE DELETE ON supplier_withdrawal_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            -- Now that the table exists, the real foreign key the previous
            -- migration named only as a plain identifier.
            ALTER TABLE supplier_ledger_entries
                ADD CONSTRAINT supplier_ledger_entries_supplier_withdrawal_id_foreign
                    FOREIGN KEY (supplier_withdrawal_id) REFERENCES supplier_withdrawals (id) ON DELETE RESTRICT;

            CREATE INDEX supplier_ledger_entries_by_withdrawal
                ON supplier_ledger_entries (supplier_withdrawal_id) WHERE supplier_withdrawal_id IS NOT NULL;

            -- A withdrawal's currency must be its wallet's own.
            CREATE OR REPLACE FUNCTION feriwala_supplier_withdrawal_currency_matches_wallet() RETURNS trigger AS $$
            DECLARE
                wallet_currency char(3);
            BEGIN
                SELECT currency_code INTO wallet_currency FROM supplier_wallets WHERE id = NEW.supplier_wallet_id;

                IF wallet_currency IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION 'a Supplier withdrawal must use its wallet''s own currency'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_withdrawals_currency_matches_wallet
                BEFORE INSERT ON supplier_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_withdrawal_currency_matches_wallet();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_withdrawals_currency_matches_wallet ON supplier_withdrawals;
            DROP FUNCTION IF EXISTS feriwala_supplier_withdrawal_currency_matches_wallet();
            DROP INDEX IF EXISTS supplier_ledger_entries_by_withdrawal;
            ALTER TABLE supplier_ledger_entries DROP CONSTRAINT IF EXISTS supplier_ledger_entries_supplier_withdrawal_id_foreign;
            DROP TRIGGER IF EXISTS supplier_withdrawal_status_history_no_delete ON supplier_withdrawal_status_history;
            DROP TRIGGER IF EXISTS supplier_withdrawal_status_history_no_update ON supplier_withdrawal_status_history;
            DROP TRIGGER IF EXISTS supplier_withdrawals_never_deleted ON supplier_withdrawals;
            DROP FUNCTION IF EXISTS feriwala_supplier_withdrawal_never_deleted();
            DROP TRIGGER IF EXISTS supplier_withdrawals_locked_columns ON supplier_withdrawals;
            DROP TRIGGER IF EXISTS supplier_payout_methods_never_deleted ON supplier_payout_methods;
            DROP FUNCTION IF EXISTS feriwala_supplier_payout_methods_never_deleted();
        SQL);

        Schema::dropIfExists('supplier_withdrawal_status_history');
        Schema::dropIfExists('supplier_withdrawals');
        Schema::dropIfExists('supplier_payout_methods');
    }
};
