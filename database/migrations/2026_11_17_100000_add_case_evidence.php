<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence for return, exchange and damage cases (D35): files, not references.
 *
 * A file goes through the shared managed-storage abstraction (`stored_files`), so
 * it is private, checksummed and lands on whichever disk — local or R2 — is in
 * use. This table is what says **whose** it is and **what it is for**: which
 * account owns the case it belongs to (the only scope a Partner is ever read
 * through), who uploaded it and as what, and at which stage of the case.
 *
 * **A Partner's evidence begins as a draft.** It is uploaded while the request is
 * still being written, belongs to the order and the uploader alone, and may be
 * removed. Submitting the request attaches it to the case it opened, and from
 * then on it is history: the database refuses to delete a submitted row and
 * refuses to change what it is. Staff add evidence to a case that already
 * exists, so theirs is submitted at once.
 *
 * The earlier reference-only evidence on `order_returns.evidence` and
 * `order_damages.evidence` is left exactly as it was written, and still shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_evidence', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('stored_file_id')->unique()->constrained('stored_files')->restrictOnDelete();
            $table->foreignId('business_account_id')->constrained('business_accounts')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_return_id')->nullable()->constrained('order_returns')->restrictOnDelete();
            $table->foreignId('order_damage_id')->nullable()->constrained('order_damages')->restrictOnDelete();

            $table->string('uploader_type', 16);
            $table->foreignId('uploader_id')->constrained('users')->restrictOnDelete();

            // request · receipt · inspection · responsibility_review
            $table->string('purpose', 24);
            $table->string('display_name', 120);
            $table->string('note', 500)->nullable();

            // draft: a Partner's, not yet part of a case · submitted: part of one, for good.
            $table->string('status', 12);
            $table->timestamp('uploaded_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['order_return_id', 'id']);
            $table->index(['order_damage_id', 'id']);
            $table->index(['business_account_id', 'order_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE case_evidence
                ADD CONSTRAINT case_evidence_status_known CHECK (status IN ('draft', 'submitted')),
                ADD CONSTRAINT case_evidence_uploader_known CHECK (uploader_type IN ('partner', 'staff')),
                ADD CONSTRAINT case_evidence_purpose_known CHECK (
                    purpose IN ('request', 'receipt', 'inspection', 'responsibility_review')
                ),
                -- A case's evidence belongs to one case, and only a submitted one is on a case.
                ADD CONSTRAINT case_evidence_belongs_to_at_most_one_case CHECK (
                    NOT (order_return_id IS NOT NULL AND order_damage_id IS NOT NULL)
                ),
                ADD CONSTRAINT case_evidence_submitted_is_on_a_case CHECK (
                    (status = 'submitted') = (order_return_id IS NOT NULL OR order_damage_id IS NOT NULL)
                ),
                ADD CONSTRAINT case_evidence_submitted_is_dated CHECK (
                    status = 'draft' OR submitted_at IS NOT NULL
                ),
                -- Only a Partner drafts: staff add to a case that exists.
                ADD CONSTRAINT case_evidence_only_a_partner_drafts CHECK (
                    status = 'submitted' OR uploader_type = 'partner'
                );

            -- What a file is, whose it is and who put it there never change.
            CREATE TRIGGER case_evidence_locked_columns
                BEFORE UPDATE OF public_id, stored_file_id, business_account_id, order_id,
                                 uploader_type, uploader_id, purpose, display_name, note, uploaded_at
                ON case_evidence
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'stored_file_id', 'business_account_id', 'order_id',
                    'uploader_type', 'uploader_id', 'purpose', 'display_name', 'note', 'uploaded_at'
                );

            -- Submitted evidence is a record of what was shown. It is not removed,
            -- and it is not turned back into a draft or moved to another case.
            CREATE OR REPLACE FUNCTION feriwala_case_evidence_is_history() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'submitted' THEN
                        RAISE EXCEPTION 'case_evidence % is submitted evidence and is never deleted', OLD.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status = 'submitted' AND (
                    NEW.status IS DISTINCT FROM OLD.status
                    OR NEW.order_return_id IS DISTINCT FROM OLD.order_return_id
                    OR NEW.order_damage_id IS DISTINCT FROM OLD.order_damage_id
                    OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at
                ) THEN
                    RAISE EXCEPTION 'case_evidence % is submitted evidence and cannot be changed', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER case_evidence_never_rewritten
                BEFORE UPDATE ON case_evidence
                FOR EACH ROW EXECUTE FUNCTION feriwala_case_evidence_is_history();
            CREATE TRIGGER case_evidence_submitted_is_never_deleted
                BEFORE DELETE ON case_evidence
                FOR EACH ROW EXECUTE FUNCTION feriwala_case_evidence_is_history();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('case_evidence');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_case_evidence_is_history();');
    }
};
