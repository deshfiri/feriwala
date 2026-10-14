<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links a KYC round to the batch version it was opened against, and gives
 * evidence somewhere to say which slot it fills and how it was captured (D30).
 *
 * Partner and Supplier rounds stay in their own tables (D25); both gain the same
 * columns. Every column is nullable or defaulted: a round opened before this has
 * no batch version, keeps reading its own requirement snapshot, and is adapted
 * by the round-definition reader — nothing about it is rewritten.
 *
 * Evidence is never deleted to make way for a replacement once a round has been
 * submitted: the old file is marked superseded and stays, so a reviewer can see
 * what was replaced and why. Reviews of single slots are append-only for the same
 * reason — the latest row for a slot is its current verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['kyc_submissions', 'supplier_kyc_submissions'] as $rounds) {
            Schema::table($rounds, function (Blueprint $table) {
                $table->foreignId('kyc_batch_id')->nullable()->constrained('kyc_batches')->restrictOnDelete();
                $table->foreignId('kyc_batch_version_id')->nullable()->constrained('kyc_batch_versions')->restrictOnDelete();

                // The option chosen in each "any one" / "minimum" group, by group
                // key: {"identity": ["national_id"]}. Changing it before
                // submission clears the draft evidence that no longer fits.
                $table->jsonb('selections')->nullable();
            });
        }

        Schema::table('kyc_documents', function (Blueprint $table) {
            // Null for a file uploaded before slots existed: it is the 'file' slot
            // of its requirement.
            $table->string('slot_key', 48)->nullable();

            // A selfie taken with the device camera, and the safe technical facts
            // about it (frame size, camera facing, user agent). Never a face
            // template and never any analysis of the face.
            $table->boolean('captured_live')->default(false);
            $table->timestamp('captured_at')->nullable();
            $table->jsonb('capture_meta')->nullable();

            $table->timestamp('superseded_at')->nullable();
        });

        Schema::table('supplier_kyc_documents', function (Blueprint $table) {
            $table->string('slot_key', 48)->nullable();
            $table->boolean('captured_live')->default(false);
            $table->timestamp('captured_at')->nullable();
            $table->jsonb('capture_meta')->nullable();
            $table->timestamp('superseded_at')->nullable();
        });

        // Typed values: one per slot. The old guarantee — one per requirement —
        // stays for the rows that have no slot.
        Schema::table('kyc_submission_fields', function (Blueprint $table) {
            $table->string('slot_key', 48)->nullable();
            $table->dropUnique('kyc_field_unique');
        });

        Schema::table('supplier_kyc_fields', function (Blueprint $table) {
            $table->string('slot_key', 48)->nullable();
            $table->dropUnique('supplier_kyc_field_unique');
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX kyc_field_unslotted_unique
                ON kyc_submission_fields (kyc_submission_id, kyc_document_type_id) WHERE slot_key IS NULL;
            CREATE UNIQUE INDEX kyc_field_slotted_unique
                ON kyc_submission_fields (kyc_submission_id, kyc_document_type_id, slot_key) WHERE slot_key IS NOT NULL;

            CREATE UNIQUE INDEX supplier_kyc_field_unslotted_unique
                ON supplier_kyc_fields (supplier_kyc_submission_id, key) WHERE slot_key IS NULL;
            CREATE UNIQUE INDEX supplier_kyc_field_slotted_unique
                ON supplier_kyc_fields (supplier_kyc_submission_id, key, slot_key) WHERE slot_key IS NOT NULL;
        SQL);

        Schema::create('kyc_evidence_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('kyc_submission_id')->constrained('kyc_submissions')->restrictOnDelete();

            // What was judged: a requirement, and one slot of it (null for the
            // selfie, which is a requirement of its own with no slots).
            $table->string('requirement_key', 64);
            $table->string('slot_key', 48)->nullable();

            $table->string('status', 12);

            // The instruction the applicant is shown. Written by a person for the
            // applicant; never a value from the evidence itself.
            $table->text('note')->nullable();

            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['kyc_submission_id', 'requirement_key', 'slot_key', 'id'], 'kyc_evidence_review_lookup');
        });

        Schema::create('supplier_kyc_evidence_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_kyc_submission_id')->constrained('supplier_kyc_submissions')->restrictOnDelete();
            $table->string('requirement_key', 64);
            $table->string('slot_key', 48)->nullable();
            $table->string('status', 12);
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['supplier_kyc_submission_id', 'requirement_key', 'slot_key', 'id'], 'supplier_kyc_evidence_review_lookup');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_evidence_reviews
                ADD CONSTRAINT kyc_evidence_reviews_status_known CHECK (status IN ('accepted', 'rejected'));
            ALTER TABLE supplier_kyc_evidence_reviews
                ADD CONSTRAINT supplier_kyc_evidence_reviews_status_known CHECK (status IN ('accepted', 'rejected'));

            CREATE TRIGGER kyc_evidence_reviews_no_update
                BEFORE UPDATE ON kyc_evidence_reviews
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER kyc_evidence_reviews_no_delete
                BEFORE DELETE ON kyc_evidence_reviews
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();

            CREATE TRIGGER supplier_kyc_evidence_reviews_no_update
                BEFORE UPDATE ON supplier_kyc_evidence_reviews
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
            CREATE TRIGGER supplier_kyc_evidence_reviews_no_delete
                BEFORE DELETE ON supplier_kyc_evidence_reviews
                FOR EACH ROW EXECUTE FUNCTION feriwala_table_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS kyc_evidence_reviews_no_update ON kyc_evidence_reviews;
            DROP TRIGGER IF EXISTS kyc_evidence_reviews_no_delete ON kyc_evidence_reviews;
            DROP TRIGGER IF EXISTS supplier_kyc_evidence_reviews_no_update ON supplier_kyc_evidence_reviews;
            DROP TRIGGER IF EXISTS supplier_kyc_evidence_reviews_no_delete ON supplier_kyc_evidence_reviews;

            DROP INDEX IF EXISTS kyc_field_unslotted_unique;
            DROP INDEX IF EXISTS kyc_field_slotted_unique;
            DROP INDEX IF EXISTS supplier_kyc_field_unslotted_unique;
            DROP INDEX IF EXISTS supplier_kyc_field_slotted_unique;
        SQL);

        Schema::dropIfExists('supplier_kyc_evidence_reviews');
        Schema::dropIfExists('kyc_evidence_reviews');

        Schema::table('supplier_kyc_fields', function (Blueprint $table) {
            $table->dropColumn('slot_key');
            $table->unique(['supplier_kyc_submission_id', 'key'], 'supplier_kyc_field_unique');
        });

        Schema::table('kyc_submission_fields', function (Blueprint $table) {
            $table->dropColumn('slot_key');
            $table->unique(['kyc_submission_id', 'kyc_document_type_id'], 'kyc_field_unique');
        });

        Schema::table('supplier_kyc_documents', function (Blueprint $table) {
            $table->dropColumn(['slot_key', 'captured_live', 'captured_at', 'capture_meta', 'superseded_at']);
        });

        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropColumn(['slot_key', 'captured_live', 'captured_at', 'capture_meta', 'superseded_at']);
        });

        foreach (['supplier_kyc_submissions', 'kyc_submissions'] as $rounds) {
            Schema::table($rounds, function (Blueprint $table) {
                $table->dropConstrainedForeignId('kyc_batch_version_id');
                $table->dropConstrainedForeignId('kyc_batch_id');
                $table->dropColumn('selections');
            });
        }
    }
};
