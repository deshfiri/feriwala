<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One active settlement per Supplier payable, held by the database (D25, P13-23).
 *
 * `supplier_payables.settled_at`/`settlement_reference` were added in
 * 2026_10_03_110000 without a lock, because P13-23 — the batch that would
 * write them — had not landed yet. It has now: {@see
 * \App\Domain\Supplier\Actions\SettleSupplierPayable} sets them once, from
 * null, and nothing may set them again afterwards. A reversal that arrives
 * later moves `status` away from `Settled` (P13-25) but never touches these
 * two columns — they stay exactly as the settlement left them, the
 * permanent record that it happened and what it posted.
 *
 * A corrective migration, not a rewrite of the one that added the columns —
 * the project's own rule for a schema mistake once other work already
 * depends on the migration that made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_supplier_payable_settlement_is_locked() RETURNS trigger AS $$
            BEGIN
                IF OLD.settled_at IS NOT NULL AND (
                    NEW.settled_at IS DISTINCT FROM OLD.settled_at
                    OR NEW.settlement_reference IS DISTINCT FROM OLD.settlement_reference
                ) THEN
                    RAISE EXCEPTION 'a Supplier payable settles once: settled_at and settlement_reference cannot change afterwards'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_payables_settlement_is_locked
                BEFORE UPDATE OF settled_at, settlement_reference ON supplier_payables
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_payable_settlement_is_locked();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_payables_settlement_is_locked ON supplier_payables;
            DROP FUNCTION IF EXISTS feriwala_supplier_payable_settlement_is_locked();
        SQL);
    }
};
