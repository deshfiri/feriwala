<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Closes a hole in the audit log's append-only guarantee.
 *
 * The UPDATE and DELETE triggers added with the table are row-level, and
 * PostgreSQL does not fire row-level triggers for TRUNCATE — it removes the rows
 * without ever visiting them. Verified against PostgreSQL directly: a `TRUNCATE
 * audit_logs` emptied a protected table without error.
 *
 * That is precisely the operation someone reaches for to erase a trail, so it
 * needs a statement-level trigger of its own (§36.2).
 *
 * Test databases are exempt: `RefreshDatabase` truncates between tests, and a
 * suite that cannot reset its own tables is unusable. Production and development
 * are protected; the guard checks the database name rather than the environment,
 * because an environment variable is easier to get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_audit_logs_no_truncate()
            RETURNS TRIGGER AS $$
            BEGIN
                IF current_database() LIKE '%test%' THEN
                    RETURN NULL;
                END IF;

                RAISE EXCEPTION
                    'audit_logs is append-only: TRUNCATE is not permitted (requirements.txt 36.2)';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;

            CREATE TRIGGER audit_logs_no_truncate
                BEFORE TRUNCATE ON audit_logs
                FOR EACH STATEMENT EXECUTE FUNCTION feriwala_audit_logs_no_truncate();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs;
            DROP FUNCTION IF EXISTS feriwala_audit_logs_no_truncate();
        SQL);
    }
};
