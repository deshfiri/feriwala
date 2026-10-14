<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the audit log be cleared — through one guarded path only (approved by the
 * Project Owner; see requirements/04-decisions.md D29).
 *
 * Nothing else about §36.2 changes. UPDATE is still refused for everyone, and
 * so is TRUNCATE. A DELETE is permitted only inside a transaction that has named
 * the purge in `feriwala.purge_audit_logs` through `set_config(..., true)` — what
 * the PurgeAuditLogs action does after it has authorised, confirmed a password
 * and a reason, and before it records the purge itself.
 *
 * The record of a purge (`audit.logs_purged`) can never be deleted, flag or no
 * flag: whoever clears the log leaves a permanent entry saying who, when, how
 * much and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_audit_logs_guard_delete()
            RETURNS TRIGGER AS $$
            BEGIN
                IF OLD.action = 'audit.logs_purged' THEN
                    RAISE EXCEPTION
                        'audit_logs: the record of a purge is permanent (requirements.txt 36.2)';
                END IF;

                IF COALESCE(current_setting('feriwala.purge_audit_logs', true), '') <> 'on' THEN
                    RAISE EXCEPTION
                        'audit_logs is append-only: DELETE is not permitted outside a guarded purge (requirements.txt 36.2)';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION feriwala_audit_logs_guard_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION feriwala_audit_logs_immutable();

            DROP FUNCTION IF EXISTS feriwala_audit_logs_guard_delete();
        SQL);
    }
};
