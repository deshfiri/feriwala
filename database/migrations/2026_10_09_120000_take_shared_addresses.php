<?php

use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shared, polymorphic address book (Location Directory + Shared Address
 * module).
 *
 * One table for both actor kinds this holds addresses for — a `BusinessAccount`
 * (Client/Partner: business and operational addresses) or a `Supplier`
 * (registered, pickup and return addresses) — resolved by `owner_type` +
 * `owner_id` rather than by two near-identical tables. `owner_type` is a plain
 * string column, not an Eloquent morph map: nothing here needs the relation
 * itself, only the pair to scope a query by (§31.3), the same way every other
 * self-scoped resource in this application is filtered by an explicit owner
 * column rather than resolved through framework polymorphism.
 *
 * `location_snapshot` freezes the resolved bilingual place names at save time,
 * so a later rename or deactivation in `bd_locations` never rewrites an
 * address already on file. `is_default` is enforced one-per-(owner, type) in
 * the application ({@see SetDefaultSharedAddress}, inside a transaction with
 * row locking) **and**, since the follow-up migration
 * `2026_10_10_100000_enforce_shared_address_invariants`, by a partial unique
 * index — the database is a second, independent guard against a write that
 * reaches this table any other way, not a replacement for the transaction.
 * Archiving is a status transition ({@see AddressStatus}), never a delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_addresses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');

            $table->string('type', 16);
            $table->string('status', 16)->default('active');

            $table->string('contact_name');
            $table->string('contact_mobile', 20);

            $table->foreignId('division_id')->constrained('bd_locations')->restrictOnDelete();
            $table->foreignId('district_id')->constrained('bd_locations')->restrictOnDelete();
            $table->foreignId('upazila_id')->constrained('bd_locations')->restrictOnDelete();
            $table->foreignId('union_id')->nullable()->constrained('bd_locations')->restrictOnDelete();

            $table->text('detailed_address');
            $table->string('landmark')->nullable();
            $table->string('postcode', 16)->nullable();
            $table->json('location_snapshot');

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['owner_type', 'owner_id', 'type', 'is_default'], 'shared_addresses_default_lookup');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE shared_addresses
                ADD CONSTRAINT shared_addresses_owner_type_known CHECK (
                    owner_type IN ('business_account', 'supplier')
                ),
                ADD CONSTRAINT shared_addresses_status_known CHECK (
                    status IN ('active', 'archived')
                ),
                ADD CONSTRAINT shared_addresses_type_known CHECK (
                    type IN ('business', 'operational', 'registered', 'pickup', 'return')
                ),
                ADD CONSTRAINT shared_addresses_contact_mobile_is_e164 CHECK (
                    contact_mobile ~ '^\+[1-9][0-9]{7,14}$'
                );

            CREATE TRIGGER shared_addresses_locked_columns
                BEFORE UPDATE OF public_id, owner_type, owner_id ON shared_addresses
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'owner_type', 'owner_id');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shared_addresses_locked_columns ON shared_addresses;
        SQL);

        Schema::dropIfExists('shared_addresses');
    }
};
