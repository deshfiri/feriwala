<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers: a wholly separate account domain from Client/Partner (D25, P13-1).
 *
 * A Supplier is never a role or a capability inside a `BusinessAccount` — it is
 * its own identity table, authenticated through its own guard
 * (`config/auth.php`), never through `users`. Nothing here references `users`
 * as the identity; `approved_by` and `suspended_by` name the **staff member**
 * who decided, which is the one place a Supplier row legitimately points at
 * the Client/Partner/staff identity table.
 *
 * Bank and trade-licence detail is commercially sensitive in the way KYC
 * documents are, so it is stored through Laravel's own encryption (`encrypted`
 * casts) rather than in the clear — a second, independent measure alongside
 * the confidentiality the application layer already enforces.
 *
 * Status moves through the shared append-only history shape (P0-16), the same
 * contract order status history and return status history already use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            // Authentication — wholly separate from `users` (D25).
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('mobile', 20)->unique();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('status', 32);

            // Business profile.
            $table->string('business_name');
            $table->string('contact_person_name');
            $table->text('business_address');
            $table->string('trade_licence_number')->nullable();
            $table->text('tax_identification_number')->nullable();

            // §36.2 / §42: bank and payout detail is never stored in the clear.
            $table->text('payout_details')->nullable();

            $table->string('locale', 5)->default('en');

            // The note a Supplier reads about the current review outcome:
            // correction instructions, or the reason for rejection/suspension.
            // The full account of every decision lives in the status history;
            // this is only ever the latest one, for the dashboard.
            $table->text('review_note')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        // Laravel's password-broker shape, for the `suppliers` broker — never
        // the same table `users` resets against (D25: separate authentication).
        Schema::create('supplier_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('supplier_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['supplier_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE suppliers
                ADD CONSTRAINT suppliers_status_known CHECK (
                    status IN (
                        'draft', 'verification_pending', 'kyc_pending', 'under_review',
                        'correction_required', 'approved', 'rejected', 'suspended', 'closed'
                    )
                ),
                ADD CONSTRAINT suppliers_email_is_lowercase CHECK (email = lower(email)),
                ADD CONSTRAINT suppliers_mobile_is_e164 CHECK (mobile ~ '^\+[1-9][0-9]{7,14}$'),
                ADD CONSTRAINT suppliers_business_name_present CHECK (length(btrim(business_name)) > 0);

            CREATE TRIGGER suppliers_locked_columns
                BEFORE UPDATE OF public_id, reference, email ON suppliers
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'reference', 'email');

            ALTER TABLE supplier_status_history
                ADD CONSTRAINT supplier_status_history_source_known CHECK (
                    source IN ('supplier', 'staff', 'system', 'scheduler')
                ),
                ADD CONSTRAINT supplier_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER supplier_status_history_no_update
                BEFORE UPDATE ON supplier_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER supplier_status_history_no_delete
                BEFORE DELETE ON supplier_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            /*
             * A Supplier's own identity is never deleted (D18-equivalent for this
             * domain): approved pricing and offer history must remain
             * attributable, so the row is closed, not removed.
             */
            CREATE OR REPLACE FUNCTION feriwala_suppliers_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a supplier is never deleted; close the account instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER suppliers_no_delete
                BEFORE DELETE ON suppliers
                FOR EACH ROW EXECUTE FUNCTION feriwala_suppliers_are_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS suppliers_no_delete ON suppliers;
            DROP FUNCTION IF EXISTS feriwala_suppliers_are_never_deleted();
            DROP TRIGGER IF EXISTS supplier_status_history_no_delete ON supplier_status_history;
            DROP TRIGGER IF EXISTS supplier_status_history_no_update ON supplier_status_history;
            DROP TRIGGER IF EXISTS suppliers_locked_columns ON suppliers;
        SQL);

        Schema::dropIfExists('supplier_status_history');
        Schema::dropIfExists('supplier_password_reset_tokens');
        Schema::dropIfExists('suppliers');
    }
};
