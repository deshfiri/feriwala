<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The provenance ledger for `bd_banks`/`bd_bank_branches`, mirroring
 * `bd_location_imports`: one append-only row per importer run, never updated
 * or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bd_bank_imports', function (Blueprint $table) {
            $table->id();

            $table->string('checksum_flat', 64);
            $table->string('checksum_website', 64);
            $table->string('checksum_audit', 64);
            $table->string('source_generated_utc', 32);
            $table->string('coverage', 64);

            $table->string('mode', 16);

            $table->unsignedInteger('banks_count');
            $table->unsignedInteger('branches_count');
            $table->unsignedInteger('deactivated_banks_count')->default(0);
            $table->unsignedInteger('deactivated_branches_count')->default(0);
            $table->unsignedInteger('unmatched_district_count')->default(0);
            $table->unsignedInteger('excluded_by_source_count')->default(0);

            $table->timestamp('imported_at');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bd_bank_imports
                ADD CONSTRAINT bd_bank_imports_mode_known CHECK (
                    mode IN ('validate', 'dry-run', 'import')
                );

            CREATE OR REPLACE FUNCTION feriwala_bd_bank_imports_are_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'bd_bank_imports is a provenance ledger and is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bd_bank_imports_no_update
                BEFORE UPDATE ON bd_bank_imports
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_bank_imports_are_append_only();

            CREATE TRIGGER bd_bank_imports_no_delete
                BEFORE DELETE ON bd_bank_imports
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_bank_imports_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bd_bank_imports_no_delete ON bd_bank_imports;
            DROP TRIGGER IF EXISTS bd_bank_imports_no_update ON bd_bank_imports;
            DROP FUNCTION IF EXISTS feriwala_bd_bank_imports_are_append_only();
        SQL);

        Schema::dropIfExists('bd_bank_imports');
    }
};
