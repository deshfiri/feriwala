<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things evidence needs once a round is built from slots (D30).
 *
 * **The live selfie has no catalogue entry.** It is a batch setting, not a
 * requirement an administrator creates, so a Partner document can now carry no
 * `kyc_document_type_id` — but only the selfie may (its slot key says so), so a
 * stray untyped file is still refused by the database. A Supplier document names
 * its requirement by a string, so it needs nothing.
 *
 * **A replaced typed value is kept.** A file replaced after a round was
 * submitted is marked superseded rather than deleted; a typed value now follows
 * the same rule instead of being overwritten, so what an applicant first wrote
 * stays on record. Uniqueness is therefore "one *current* value per slot".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_documents ALTER COLUMN kyc_document_type_id DROP NOT NULL;

            ALTER TABLE kyc_documents
                ADD CONSTRAINT kyc_documents_type_or_selfie CHECK (
                    kyc_document_type_id IS NOT NULL OR slot_key = 'capture'
                );
        SQL);

        foreach (['kyc_submission_fields', 'supplier_kyc_fields'] as $fields) {
            Schema::table($fields, function (Blueprint $table) {
                $table->timestamp('superseded_at')->nullable();
            });
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX kyc_field_unslotted_unique;
            DROP INDEX kyc_field_slotted_unique;
            DROP INDEX supplier_kyc_field_unslotted_unique;
            DROP INDEX supplier_kyc_field_slotted_unique;

            CREATE UNIQUE INDEX kyc_field_unslotted_unique
                ON kyc_submission_fields (kyc_submission_id, kyc_document_type_id)
                WHERE slot_key IS NULL AND superseded_at IS NULL;
            CREATE UNIQUE INDEX kyc_field_slotted_unique
                ON kyc_submission_fields (kyc_submission_id, kyc_document_type_id, slot_key)
                WHERE slot_key IS NOT NULL AND superseded_at IS NULL;

            CREATE UNIQUE INDEX supplier_kyc_field_unslotted_unique
                ON supplier_kyc_fields (supplier_kyc_submission_id, key)
                WHERE slot_key IS NULL AND superseded_at IS NULL;
            CREATE UNIQUE INDEX supplier_kyc_field_slotted_unique
                ON supplier_kyc_fields (supplier_kyc_submission_id, key, slot_key)
                WHERE slot_key IS NOT NULL AND superseded_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX kyc_field_unslotted_unique;
            DROP INDEX kyc_field_slotted_unique;
            DROP INDEX supplier_kyc_field_unslotted_unique;
            DROP INDEX supplier_kyc_field_slotted_unique;

            CREATE UNIQUE INDEX kyc_field_unslotted_unique
                ON kyc_submission_fields (kyc_submission_id, kyc_document_type_id) WHERE slot_key IS NULL;
            CREATE UNIQUE INDEX kyc_field_slotted_unique
                ON kyc_submission_fields (kyc_submission_id, kyc_document_type_id, slot_key) WHERE slot_key IS NOT NULL;
            CREATE UNIQUE INDEX supplier_kyc_field_unslotted_unique
                ON supplier_kyc_fields (supplier_kyc_submission_id, key) WHERE slot_key IS NULL;
            CREATE UNIQUE INDEX supplier_kyc_field_slotted_unique
                ON supplier_kyc_fields (supplier_kyc_submission_id, key, slot_key) WHERE slot_key IS NOT NULL;
        SQL);

        foreach (['kyc_submission_fields', 'supplier_kyc_fields'] as $fields) {
            Schema::table($fields, function (Blueprint $table) {
                $table->dropColumn('superseded_at');
            });
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE kyc_documents DROP CONSTRAINT kyc_documents_type_or_selfie;
            ALTER TABLE kyc_documents ALTER COLUMN kyc_document_type_id SET NOT NULL;
        SQL);
    }
};
