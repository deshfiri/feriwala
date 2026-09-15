<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The database half of the shared status-history contract (P0-16).
 *
 * One trigger function any status history table attaches for UPDATE and DELETE,
 * so a row written through a migration, a console command or a psql prompt is as
 * append-only as one written through Eloquent. The message names the table that
 * was touched.
 *
 * No existing history is attached here: each history table opts in when it is
 * created or reworked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_status_history_is_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    '% is a status history and is append-only: % is not permitted.',
                    TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_status_history_is_append_only();');
    }
};
