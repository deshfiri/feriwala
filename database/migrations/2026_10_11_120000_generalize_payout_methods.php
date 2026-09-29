<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalizes the Supplier-only `supplier_payout_methods` table into a
 * shared, owner-polymorphic `payout_methods` table serving both Supplier and
 * Client/Partner (BusinessAccount) owners — one payout system, not two,
 * following the same `owner_type`/`owner_id` shape `shared_addresses` already
 * established for exactly this kind of two-owner-kind resource.
 *
 * `supplier_payout_methods` is dropped rather than altered in place: it has
 * zero rows in every environment this application runs in (this is the same
 * pre-launch build that created it), so there is nothing to migrate and
 * nothing gained by carrying its Supplier-specific shape forward.
 * `supplier_withdrawals.supplier_payout_method_id` is renamed to the
 * owner-neutral `payout_method_id` and repointed at the new table; every
 * other column, trigger and constraint on that table (its currency-matches-
 * wallet trigger, its ledger FK, its own locked/append-only rules) is
 * untouched.
 *
 * New here, beyond what `supplier_payout_methods` had:
 *   - `bd_bank_id`/`bd_bank_branch_id` — real FKs into the bank directory for
 *     a bank-account method, never inside the encrypted `details` blob
 *     (which bank/branch a method uses is not itself sensitive).
 *   - `fingerprint` — a keyed, non-reversible hash of the account number
 *     (see App\Domain\Payout\Actions\SavePayoutMethod), enforced unique per
 *     owner while active. `supplier_payout_methods` had no such column, so
 *     nothing stopped the same account being registered twice under two
 *     labels; this closes that gap for both owner kinds at once.
 *   - a DB-level partial unique index enforcing one active default per
 *     owner, on top of the existing app-level targeted-update convention —
 *     the same belt-and-braces the `shared_addresses` invariants migration
 *     already applied to its own one-default-per-owner rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The old table can't be dropped while supplier_withdrawals' FK still
        // names it -- drop that constraint first, restore it against the new
        // table once payout_methods exists.
        DB::unprepared('ALTER TABLE supplier_withdrawals DROP CONSTRAINT supplier_withdrawals_supplier_payout_method_id_foreign;');

        Schema::dropIfExists('supplier_payout_methods');

        Schema::create('payout_methods', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');

            $table->string('type', 20);
            $table->string('label', 80);

            $table->foreignId('bd_bank_id')->nullable()->constrained('bd_banks')->restrictOnDelete();
            $table->foreignId('bd_bank_branch_id')->nullable()->constrained('bd_bank_branches')->restrictOnDelete();

            $table->text('details')->nullable();
            $table->string('last_four', 4);
            $table->string('fingerprint', 64);

            $table->boolean('is_default')->default(false);
            $table->string('status', 16)->default('active');

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE payout_methods
                ADD CONSTRAINT payout_methods_type_known CHECK (
                    type IN ('bank_account', 'bkash', 'nagad')
                ),
                ADD CONSTRAINT payout_methods_status_known CHECK (
                    status IN ('active', 'archived')
                ),
                ADD CONSTRAINT payout_methods_owner_type_known CHECK (
                    owner_type IN ('business_account', 'supplier')
                ),
                ADD CONSTRAINT payout_methods_bank_fields_match_type CHECK (
                    (type = 'bank_account') = (bd_bank_id IS NOT NULL AND bd_bank_branch_id IS NOT NULL)
                );

            -- One active default per owner, enforced at the database as well
            -- as by the targeted UPDATE in SavePayoutMethod::makeDefault().
            CREATE UNIQUE INDEX payout_methods_one_default_per_owner
                ON payout_methods (owner_type, owner_id)
                WHERE is_default AND status = 'active';

            -- The same account registered twice under two labels is refused,
            -- scoped to one owner while active -- an archived duplicate
            -- (superseded, or a corrected typo) does not block a new one.
            CREATE UNIQUE INDEX payout_methods_fingerprint_unique_per_owner
                ON payout_methods (owner_type, owner_id, fingerprint)
                WHERE status = 'active';

            CREATE TRIGGER payout_methods_locked_owner
                BEFORE UPDATE OF owner_type, owner_id ON payout_methods
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('owner_type', 'owner_id');

            CREATE OR REPLACE FUNCTION feriwala_payout_methods_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a payout method is never deleted: archive it instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payout_methods_never_deleted
                BEFORE DELETE ON payout_methods
                FOR EACH ROW EXECUTE FUNCTION feriwala_payout_methods_never_deleted();

            ALTER TABLE supplier_withdrawals
                RENAME COLUMN supplier_payout_method_id TO payout_method_id;

            ALTER TABLE supplier_withdrawals
                ADD CONSTRAINT supplier_withdrawals_payout_method_id_foreign
                    FOREIGN KEY (payout_method_id) REFERENCES payout_methods (id) ON DELETE RESTRICT;

            DROP TRIGGER IF EXISTS supplier_withdrawals_locked_columns ON supplier_withdrawals;

            CREATE TRIGGER supplier_withdrawals_locked_columns
                BEFORE UPDATE OF
                    public_id, reference, supplier_id, supplier_wallet_id, payout_method_id,
                    payout_snapshot, amount, currency_code, idempotency_key
                ON supplier_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'supplier_wallet_id', 'payout_method_id',
                    'payout_snapshot', 'amount', 'currency_code', 'idempotency_key'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_withdrawals_locked_columns ON supplier_withdrawals;

            CREATE TRIGGER supplier_withdrawals_locked_columns
                BEFORE UPDATE OF
                    public_id, reference, supplier_id, supplier_wallet_id, payout_method_id,
                    payout_snapshot, amount, currency_code, idempotency_key
                ON supplier_withdrawals
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'supplier_wallet_id', 'payout_method_id',
                    'payout_snapshot', 'amount', 'currency_code', 'idempotency_key'
                );

            ALTER TABLE supplier_withdrawals DROP CONSTRAINT supplier_withdrawals_payout_method_id_foreign;
            ALTER TABLE supplier_withdrawals RENAME COLUMN payout_method_id TO supplier_payout_method_id;
        SQL);

        Schema::dropIfExists('payout_methods');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_payout_methods_never_deleted();
        SQL);

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

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_withdrawals
                ADD CONSTRAINT supplier_withdrawals_supplier_payout_method_id_foreign
                    FOREIGN KEY (supplier_payout_method_id) REFERENCES supplier_payout_methods (id) ON DELETE RESTRICT;
        SQL);
    }
};
