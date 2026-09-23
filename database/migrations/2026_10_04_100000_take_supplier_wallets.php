<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A Supplier's own wallet and financial ledger (D25, P13-23).
 *
 * **Deliberately parallel, never attached to a Business Account.** `wallets`
 * and `ledger_entries` are owned by `business_account_id` NOT NULL, cascading
 * on delete, and every write path in `App\Domain\Wallet\WalletService` reads
 * that column directly — retrofitting a nullable, polymorphic owner onto a
 * table the whole existing Client/Partner financial system depends on would
 * be exactly the kind of change this batch was told to avoid. A Supplier is
 * a wholly separate account type (D25), so it gets its own wallet and its
 * own ledger, built to the same invariants as the original — immutable
 * entries, row-locked posting, idempotent by key — rather than sharing its
 * table or its service.
 *
 * `supplier_wallets` keeps three primitive buckets, exactly as the original
 * keeps buckets rather than derived totals (§24.2's own reasoning): `total`
 * is everything ever credited less everything ever debited; `reserved` is
 * money set aside for a withdrawal not yet paid or released; `recovery` is
 * the outstanding amount owed back to Feriwala when a reversal arrived after
 * the money had already been settled and there was not enough available to
 * take it back from directly (P13-23's "controlled debt", never a negative
 * balance). Available balance is `total - reserved - recovery`, worked out
 * in the model and nowhere else — which is also what makes a withdrawal
 * request needing money tied up in an existing recovery refuse itself,
 * without a separate check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_wallets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->char('currency_code', 3)->default('BDT');

            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('reserved_minor')->default(0);
            $table->bigInteger('recovery_minor')->default(0);

            $table->timestamps();

            $table->unique(['supplier_id', 'currency_code']);
        });

        Schema::create('supplier_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('supplier_wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('type', 40);
            $table->string('source', 40);

            $table->foreignId('supplier_payable_id')->nullable()->constrained('supplier_payables')->restrictOnDelete();
            $table->foreignId('supplier_payable_reversal_id')->nullable()->constrained('supplier_payable_reversals')->restrictOnDelete();

            // supplier_withdrawals does not exist yet in this migration —
            // exactly the "plain identifier where the owning module is still
            // to come" that ledger_entries itself uses. The real foreign key
            // is added once that table exists (2026_10_04_110000).
            $table->unsignedBigInteger('supplier_withdrawal_id')->nullable();

            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->char('currency_code', 3);

            $table->bigInteger('balance_before_minor');
            $table->bigInteger('balance_after_minor');
            $table->bigInteger('reserved_before_minor');
            $table->bigInteger('reserved_after_minor');
            $table->bigInteger('recovery_before_minor');
            $table->bigInteger('recovery_after_minor');

            $table->string('description');
            $table->text('internal_note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->foreignId('corrects_ledger_entry_id')->nullable()->constrained('supplier_ledger_entries')->nullOnDelete();

            $table->timestamp('created_at');

            $table->index(['supplier_wallet_id', 'id']);
            $table->index(['supplier_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_wallets
                ADD CONSTRAINT supplier_wallets_amounts_not_negative CHECK (
                    total_minor >= 0 AND reserved_minor >= 0 AND recovery_minor >= 0
                ),
                ADD CONSTRAINT supplier_wallets_reserved_within_total CHECK (reserved_minor <= total_minor),
                ADD CONSTRAINT supplier_wallets_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$');

            CREATE TRIGGER supplier_wallets_locked_columns
                BEFORE UPDATE OF public_id, supplier_id, currency_code ON supplier_wallets
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'supplier_id', 'currency_code');

            ALTER TABLE supplier_ledger_entries
                ADD CONSTRAINT supplier_ledger_entries_amounts_not_negative CHECK (
                    debit_minor >= 0 AND credit_minor >= 0
                ),
                -- Never both positive — one column is the movement, the other
                -- zero. Both zero is allowed: a recovery bookkeeping entry
                -- moves the recovery bucket only, and total_minor with it,
                -- so it carries no debit or credit of its own.
                ADD CONSTRAINT supplier_ledger_entries_one_direction CHECK (
                    NOT (debit_minor > 0 AND credit_minor > 0)
                ),
                ADD CONSTRAINT supplier_ledger_entries_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                -- Every bucket's arithmetic holds, checked by the database as
                -- well as by the posting service.
                ADD CONSTRAINT supplier_ledger_entries_balance_adds_up CHECK (
                    balance_after_minor = balance_before_minor + credit_minor - debit_minor
                ),
                ADD CONSTRAINT supplier_ledger_entries_buckets_not_negative CHECK (
                    balance_after_minor >= 0 AND reserved_after_minor >= 0 AND recovery_after_minor >= 0
                );

            -- Append-only: a posted entry is corrected by a new entry, never by
            -- rewriting or removing the one it corrects (mirrors ledger_entries).
            CREATE OR REPLACE FUNCTION feriwala_supplier_ledger_entries_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'supplier_ledger_entries is append-only'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_ledger_entries_no_update
                BEFORE UPDATE ON supplier_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_ledger_entries_immutable();

            CREATE TRIGGER supplier_ledger_entries_no_delete
                BEFORE DELETE ON supplier_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_ledger_entries_immutable();

            -- An entry's currency must be the wallet's own (D4: no cross-
            -- currency settlement anywhere in this batch).
            CREATE OR REPLACE FUNCTION feriwala_supplier_ledger_entry_currency_matches_wallet() RETURNS trigger AS $$
            DECLARE
                wallet_currency char(3);
            BEGIN
                SELECT currency_code INTO wallet_currency FROM supplier_wallets WHERE id = NEW.supplier_wallet_id;

                IF wallet_currency IS DISTINCT FROM NEW.currency_code THEN
                    RAISE EXCEPTION 'a Supplier ledger entry must use its wallet''s own currency'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_ledger_entries_currency_matches_wallet
                BEFORE INSERT ON supplier_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_ledger_entry_currency_matches_wallet();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_ledger_entries_currency_matches_wallet ON supplier_ledger_entries;
            DROP FUNCTION IF EXISTS feriwala_supplier_ledger_entry_currency_matches_wallet();
            DROP TRIGGER IF EXISTS supplier_ledger_entries_no_delete ON supplier_ledger_entries;
            DROP TRIGGER IF EXISTS supplier_ledger_entries_no_update ON supplier_ledger_entries;
            DROP FUNCTION IF EXISTS feriwala_supplier_ledger_entries_immutable();
            DROP TRIGGER IF EXISTS supplier_wallets_locked_columns ON supplier_wallets;
        SQL);

        Schema::dropIfExists('supplier_ledger_entries');
        Schema::dropIfExists('supplier_wallets');
    }
};
