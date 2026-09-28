<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Bangladesh administrative directory: Division -> District -> Upazila ->
 * Union, bundled from a pinned upstream commit (see
 * database/data/bangladesh-location/NOTICE.md) rather than fetched at runtime.
 *
 * One shared table rather than four: every level has the same shape (a
 * bilingual name, a parent, an active flag), and a single table keeps
 * children-of/ancestors-of queries uniform across all four levels instead of
 * needing a different join per level.
 *
 * The upstream data's own ids (`source_id`) are confirmed unique only within
 * their immediate parent, not globally, so the natural key a row is imported
 * and matched against is `(type, source_id, source_parent_id)` -- hierarchy is
 * part of identity, never inferred from a name. Once written, that identity
 * never changes: a re-import that would move a node under a different parent
 * is a different natural key, so it creates a new row and the old one is
 * deactivated, never mutated in place (see `ImportBdLocations`).
 *
 * Reference data, never deleted -- a location still referenced by an address
 * that becomes obsolete upstream is deactivated (`is_active = false`), not
 * removed, so history stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bd_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('bd_locations')->restrictOnDelete();

            $table->string('type', 16);
            $table->string('source_id', 32);
            $table->string('source_parent_id', 32)->nullable();

            $table->string('name_en');
            $table->string('name_bn');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['type', 'source_id', 'source_parent_id'], 'bd_locations_natural_key');
            $table->index(['type', 'parent_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bd_locations
                ADD CONSTRAINT bd_locations_type_known CHECK (
                    type IN ('division', 'district', 'upazila', 'union')
                ),
                ADD CONSTRAINT bd_locations_division_has_no_parent CHECK (
                    (type = 'division') = (parent_id IS NULL)
                ),
                ADD CONSTRAINT bd_locations_division_has_no_source_parent CHECK (
                    (type = 'division') = (source_parent_id IS NULL)
                );

            CREATE TRIGGER bd_locations_locked_columns
                BEFORE UPDATE OF type, source_id, source_parent_id, parent_id ON bd_locations
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('type', 'source_id', 'source_parent_id', 'parent_id');

            /*
             * Deactivate, never delete -- the importer only ever inserts or
             * flips `is_active`, but this closes the door on anything else
             * (a tinker session, a future job) reaching for a DELETE instead.
             */
            CREATE OR REPLACE FUNCTION feriwala_bd_locations_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a location is never deleted; it is deactivated instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bd_locations_no_delete
                BEFORE DELETE ON bd_locations
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_locations_are_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bd_locations_no_delete ON bd_locations;
            DROP FUNCTION IF EXISTS feriwala_bd_locations_are_never_deleted();
            DROP TRIGGER IF EXISTS bd_locations_locked_columns ON bd_locations;
        SQL);

        Schema::dropIfExists('bd_locations');
    }
};
