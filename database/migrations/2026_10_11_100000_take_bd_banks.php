<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Bangladesh bank and branch directory (see
 * database/data/bangladesh-bank/NOTICE.md), linked to the existing
 * `bd_locations` directory by district rather than by free text.
 *
 * `bd_banks.bank_code` is the three-digit BEFTN bank code; a branch's
 * nine-digit routing number is globally unique across every bank (its own
 * first three digits already identify the bank, so `routing_number` alone
 * carries the unique constraint — `bank_id` is still a real FK for joins).
 * `district_location_id` resolves to `bd_locations` through
 * `App\Domain\Bank\DistrictAliases` plus a normalized name match — never
 * inferred loosely — and stays nullable because a future, less complete
 * source might legitimately fail to resolve one; an unresolved branch is
 * still imported; it is reported as unmatched, not dropped.
 *
 * Never deleted, same as `bd_locations`: a branch or bank missing from a
 * later import is deactivated (`is_active = false`), and its identity
 * columns are locked once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bd_banks', function (Blueprint $table) {
            $table->id();

            $table->string('bank_code', 3);
            $table->string('name');
            $table->string('slug');
            $table->json('aliases')->nullable();

            // null = unidentified in the source (e.g. code 050) — distinct
            // from false, which means a confirmed non-payable institution.
            $table->boolean('payable')->nullable();
            $table->boolean('available_in_selector')->default(false);
            $table->unsignedInteger('branch_count')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('bank_code');
        });

        Schema::create('bd_bank_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('bd_banks')->restrictOnDelete();

            $table->string('routing_number', 9);
            $table->string('name');
            $table->string('slug');

            $table->foreignId('district_location_id')->nullable()->constrained('bd_locations')->restrictOnDelete();
            $table->string('district_source_name');

            $table->string('branch_code', 16)->nullable();
            $table->string('original_branch_code', 32)->nullable();
            $table->string('swift_code', 16)->nullable();
            $table->text('address')->nullable();
            $table->string('telephone', 64)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('fax', 64)->nullable();

            $table->string('source', 32);
            $table->string('source_status', 32);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('routing_number');
            $table->index(['bank_id', 'is_active']);
            $table->index('district_location_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE bd_bank_branches
                ADD CONSTRAINT bd_bank_branches_routing_number_format CHECK (
                    routing_number ~ '^[0-9]{9}$'
                );

            -- A CHECK constraint cannot look up another table, so the
            -- "routing number's own bank-code prefix must match its bank"
            -- rule (already true of every bundled row -- see NOTICE.md) is
            -- enforced by trigger instead.
            CREATE OR REPLACE FUNCTION feriwala_bd_bank_branch_routing_matches_bank() RETURNS trigger AS $$
            DECLARE
                expected_prefix char(3);
            BEGIN
                SELECT bank_code INTO expected_prefix FROM bd_banks WHERE id = NEW.bank_id;

                IF substring(NEW.routing_number from 1 for 3) != expected_prefix THEN
                    RAISE EXCEPTION 'routing number % does not start with bank % own code %',
                        NEW.routing_number, NEW.bank_id, expected_prefix
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bd_bank_branches_routing_matches_bank
                BEFORE INSERT OR UPDATE OF bank_id, routing_number ON bd_bank_branches
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_bank_branch_routing_matches_bank();

            CREATE TRIGGER bd_banks_locked_columns
                BEFORE UPDATE OF bank_code ON bd_banks
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('bank_code');

            CREATE TRIGGER bd_bank_branches_locked_columns
                BEFORE UPDATE OF bank_id, routing_number ON bd_bank_branches
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('bank_id', 'routing_number');

            CREATE OR REPLACE FUNCTION feriwala_bd_banks_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a bank directory row is never deleted; it is deactivated instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bd_banks_no_delete
                BEFORE DELETE ON bd_banks
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_banks_are_never_deleted();

            CREATE TRIGGER bd_bank_branches_no_delete
                BEFORE DELETE ON bd_bank_branches
                FOR EACH ROW EXECUTE FUNCTION feriwala_bd_banks_are_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bd_bank_branches_no_delete ON bd_bank_branches;
            DROP TRIGGER IF EXISTS bd_banks_no_delete ON bd_banks;
            DROP FUNCTION IF EXISTS feriwala_bd_banks_are_never_deleted();
            DROP TRIGGER IF EXISTS bd_bank_branches_locked_columns ON bd_bank_branches;
            DROP TRIGGER IF EXISTS bd_banks_locked_columns ON bd_banks;
        SQL);

        Schema::dropIfExists('bd_bank_branches');
        Schema::dropIfExists('bd_banks');
    }
};
