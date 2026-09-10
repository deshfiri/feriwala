<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The ledger is append-only, enforced by the database (§23.2).
 *
 * The model already refuses to update or delete an entry, and that is the guard
 * a developer meets first. It is not the guard that matters: the query builder
 * fires no model events, so `DB::table('ledger_entries')->update(...)` walks
 * straight past it — and so does `php artisan tinker`, a migration, a seeder,
 * and anybody with a psql prompt.
 *
 * A trigger is the only place the rule can be stated once and hold for all of
 * them. §23.2 says ledger entries must not be directly edited or deleted; this
 * is that sentence, in the one language every writer has to speak.
 *
 * Corrections are new rows pointing at what they correct. That is deliberately
 * the only way through: the wrong figure stays visible beside the putting-right
 * of it, which is what makes a ledger auditable rather than merely current.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_ledger_is_append_only()
            RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION
                    'ledger_entries is append-only (requirements.txt 23.2): % is not permitted. Post a correcting entry instead.',
                    TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_no_update
                BEFORE UPDATE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION feriwala_ledger_is_append_only();

            CREATE TRIGGER ledger_entries_no_delete
                BEFORE DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION feriwala_ledger_is_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries;
            DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries;
            DROP FUNCTION IF EXISTS feriwala_ledger_is_append_only();
        SQL);
    }
};
