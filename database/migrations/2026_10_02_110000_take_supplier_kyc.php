<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier KYC: a lean, purpose-built submission and document model (D25,
 * P13-7).
 *
 * Deliberately **not** the generic KYC engine `App\Domain\Kyc` builds for
 * Client/Partner `BusinessAccount`s under §7 — that engine's
 * `kyc_submissions.business_account_id` foreign key is exactly the single-
 * account-structure binding D1/D23 protect, and widening it would touch a
 * historical migration. The two are procedurally similar (submit, review,
 * approve/reject/request-correction) on purpose; they share no table.
 *
 * Documents live on the same private, encrypted `kyc` disk §7.5 already
 * requires, under their own path prefix, read only through
 * `SupplierKycDocumentStore` — never a public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('round');

            $table->string('status', 32);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->unique(['supplier_id', 'round']);
        });

        Schema::create('supplier_kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_kyc_submission_id')->constrained()->restrictOnDelete();

            $table->string('document_type', 64);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->boolean('is_encrypted')->default(true);

            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_kyc_submissions
                ADD CONSTRAINT supplier_kyc_submissions_status_known CHECK (
                    status IN ('draft', 'submitted', 'under_review', 'correction_required', 'approved', 'rejected')
                ),
                ADD CONSTRAINT supplier_kyc_submissions_round_positive CHECK (round > 0);

            CREATE TRIGGER supplier_kyc_submissions_locked_columns
                BEFORE UPDATE OF public_id, supplier_id, round ON supplier_kyc_submissions
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'supplier_id', 'round');

            CREATE TRIGGER supplier_kyc_documents_locked_columns
                BEFORE UPDATE OF public_id, supplier_kyc_submission_id, disk, path, checksum ON supplier_kyc_documents
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'supplier_kyc_submission_id', 'disk', 'path', 'checksum'
                );

            /*
             * A submitted round's documents are evidence behind a decision
             * (mirrors §7.3 for the Client/Partner engine) and are never removed
             * once the round leaves draft.
             */
            CREATE OR REPLACE FUNCTION feriwala_supplier_kyc_document_kept() RETURNS trigger AS $$
            DECLARE
                round_status text;
            BEGIN
                SELECT status INTO round_status FROM supplier_kyc_submissions WHERE id = OLD.supplier_kyc_submission_id;

                IF round_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'a submitted supplier KYC document cannot be deleted'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_kyc_documents_kept
                BEFORE DELETE ON supplier_kyc_documents
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_kyc_document_kept();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_kyc_documents_kept ON supplier_kyc_documents;
            DROP FUNCTION IF EXISTS feriwala_supplier_kyc_document_kept();
            DROP TRIGGER IF EXISTS supplier_kyc_documents_locked_columns ON supplier_kyc_documents;
            DROP TRIGGER IF EXISTS supplier_kyc_submissions_locked_columns ON supplier_kyc_submissions;
        SQL);

        Schema::dropIfExists('supplier_kyc_documents');
        Schema::dropIfExists('supplier_kyc_submissions');
    }
};
