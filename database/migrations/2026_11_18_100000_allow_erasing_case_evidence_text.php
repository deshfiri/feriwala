<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A narrow, one-way way to erase what submitted case evidence says about people (D35).
 *
 * Submitted evidence stays immutable. `case_evidence_locked_columns` froze every
 * descriptive column of it, including two that can carry personal data — the
 * file's original name and the note beside it — and the stored file itself. An
 * erased account has to be able to lose those, and **only** those, and only in
 * one direction:
 *
 *   - `display_name` may move from what it was to the fixed value `[redacted]`,
 *     never anywhere else, and once redacted never again;
 *   - `note` may move from a note to null or `[redacted]`, never from nothing to
 *     something, and once redacted never again;
 *   - the stored file may be **detached once**: `stored_file_id` goes from a file
 *     to null in the same statement that stamps `file_erased_at`, and neither
 *     changes afterwards (a check says a row has a file or says it was erased);
 *   - the case it belongs to, the account, the order, the purpose, who uploaded
 *     it and when, and its submission stay locked exactly as before, and
 *     submitted evidence is still never deleted (the history trigger is untouched).
 *
 * The earlier migration is left as it was; `down` restores its trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_evidence', function (Blueprint $table) {
            $table->timestamp('file_erased_at')->nullable()->after('uploaded_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE case_evidence ALTER COLUMN stored_file_id DROP NOT NULL;
            ALTER TABLE case_evidence ADD CONSTRAINT case_evidence_has_a_file_or_was_erased
                CHECK (stored_file_id IS NOT NULL OR file_erased_at IS NOT NULL);

            DROP TRIGGER IF EXISTS case_evidence_locked_columns ON case_evidence;

            CREATE OR REPLACE FUNCTION feriwala_case_evidence_columns_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.business_account_id IS DISTINCT FROM OLD.business_account_id
                    OR NEW.order_id IS DISTINCT FROM OLD.order_id
                    OR NEW.uploader_type IS DISTINCT FROM OLD.uploader_type
                    OR NEW.uploader_id IS DISTINCT FROM OLD.uploader_id
                    OR NEW.purpose IS DISTINCT FROM OLD.purpose
                    OR NEW.uploaded_at IS DISTINCT FROM OLD.uploaded_at THEN
                    RAISE EXCEPTION 'case_evidence % has locked columns that cannot change', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- One way, to a fixed value, once.
                IF NEW.display_name IS DISTINCT FROM OLD.display_name
                    AND (NEW.display_name <> '[redacted]' OR OLD.display_name = '[redacted]') THEN
                    RAISE EXCEPTION 'case_evidence % display_name can only be redacted, once', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.note IS DISTINCT FROM OLD.note
                    AND (OLD.note IS NULL OR OLD.note = '[redacted]'
                         OR (NEW.note IS NOT NULL AND NEW.note <> '[redacted]')) THEN
                    RAISE EXCEPTION 'case_evidence % note can only be redacted, once', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- The file goes once, and is stamped as gone in the same statement.
                IF NEW.stored_file_id IS DISTINCT FROM OLD.stored_file_id
                    AND NOT (OLD.stored_file_id IS NOT NULL AND NEW.stored_file_id IS NULL
                             AND OLD.file_erased_at IS NULL AND NEW.file_erased_at IS NOT NULL) THEN
                    RAISE EXCEPTION 'case_evidence % file can only be detached once', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.file_erased_at IS DISTINCT FROM OLD.file_erased_at
                    AND (OLD.file_erased_at IS NOT NULL OR NEW.stored_file_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'case_evidence % file_erased_at is set once, with the detach', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER case_evidence_columns_guard
                BEFORE UPDATE ON case_evidence
                FOR EACH ROW EXECUTE FUNCTION feriwala_case_evidence_columns_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS case_evidence_columns_guard ON case_evidence;
            DROP FUNCTION IF EXISTS feriwala_case_evidence_columns_guard();
            ALTER TABLE case_evidence DROP CONSTRAINT IF EXISTS case_evidence_has_a_file_or_was_erased;
            ALTER TABLE case_evidence ALTER COLUMN stored_file_id SET NOT NULL;

            CREATE TRIGGER case_evidence_locked_columns
                BEFORE UPDATE OF public_id, stored_file_id, business_account_id, order_id,
                                 uploader_type, uploader_id, purpose, display_name, note, uploaded_at
                ON case_evidence
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'stored_file_id', 'business_account_id', 'order_id',
                    'uploader_type', 'uploader_id', 'purpose', 'display_name', 'note', 'uploaded_at'
                );
        SQL);

        Schema::table('case_evidence', function (Blueprint $table) {
            $table->dropColumn('file_erased_at');
        });
    }
};
