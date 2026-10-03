<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence that staff -- not the person -- confirmed an email address or a
 * mobile number.
 *
 * The identity tables only record *that* a channel is verified. This records
 * who confirmed it on someone's behalf and why, kept apart so the person's own
 * verification history is never overwritten or blurred: a channel is
 * verified-by-staff here, or verified by the person elsewhere, never both.
 *
 * One row per identity and channel (a second confirmation is refused, not
 * recorded again), and rows are append-only by trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_contact_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('identity_type', 16);
            $table->unsignedBigInteger('identity_id');
            $table->string('channel', 16);
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('created_at');

            $table->unique(['identity_type', 'identity_id', 'channel']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE staff_contact_verifications
                ADD CONSTRAINT staff_contact_verifications_identity_known CHECK (identity_type IN ('user', 'supplier')),
                ADD CONSTRAINT staff_contact_verifications_channel_known CHECK (channel IN ('email', 'mobile'));

            CREATE OR REPLACE FUNCTION feriwala_staff_contact_verifications_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'staff contact verifications are append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER staff_contact_verifications_append_only
                BEFORE UPDATE OR DELETE ON staff_contact_verifications
                FOR EACH ROW EXECUTE FUNCTION feriwala_staff_contact_verifications_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS staff_contact_verifications_append_only ON staff_contact_verifications;
            DROP FUNCTION IF EXISTS feriwala_staff_contact_verifications_append_only();
        SQL);

        Schema::dropIfExists('staff_contact_verifications');
    }
};
