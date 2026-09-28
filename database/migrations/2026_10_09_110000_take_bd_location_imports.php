<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The provenance ledger for `bd_locations` (§NN, Location Directory).
 *
 * One append-only row per importer run -- source URL, the pinned upstream
 * commit, both files' checksums, the license, which mode ran, and how many
 * rows of each level it touched. Never updated or deleted, the same as any
 * other history table in this application, so "when was this last imported
 * and against what" is always answerable from the row itself rather than
 * from a mutable settings value.
 *
 * Its latest id also serves as the location-directory's cache-bust version:
 * the child-lookup endpoints key their cache on it, so a re-import
 * invalidates every cached key without a manual flush (see
 * `LocationLookupController`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bd_location_imports', function (Blueprint $table) {
            $table->id();

            $table->string('source_url');
            $table->string('commit_sha', 40);
            $table->string('checksum_en', 64);
            $table->string('checksum_bn', 64);
            $table->string('license', 16)->default('MIT');

            $table->string('mode', 16);

            $table->unsignedInteger('divisions_count');
            $table->unsignedInteger('districts_count');
            $table->unsignedInteger('upazilas_count');
            $table->unsignedInteger('unions_count');
            $table->unsignedInteger('deactivated_count')->default(0);

            $table->timestamp('imported_at');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bd_location_imports
                ADD CONSTRAINT bd_location_imports_mode_known CHECK (
                    mode IN ('validate', 'dry-run', 'import')
                );

            CREATE OR REPLACE FUNCTION feriwala_bd_location_imports_are_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'bd_location_imports is a provenance ledger and is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bd_location_imports_no_update
                BEFORE UPDATE ON bd_location_imports
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_location_imports_are_append_only();

            CREATE TRIGGER bd_location_imports_no_delete
                BEFORE DELETE ON bd_location_imports
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_location_imports_are_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bd_location_imports_no_delete ON bd_location_imports;
            DROP TRIGGER IF EXISTS bd_location_imports_no_update ON bd_location_imports;
            DROP FUNCTION IF EXISTS feriwala_bd_location_imports_are_append_only();
        SQL);

        Schema::dropIfExists('bd_location_imports');
    }
};
