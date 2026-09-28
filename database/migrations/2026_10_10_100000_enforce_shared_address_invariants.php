<?php

use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three `shared_addresses` invariants the original migration left to the
 * application alone, closed here at the database level too (Address batch
 * correction):
 *
 * 1. **One active default per (owner_type, owner_id, type).** Previously
 *    enforced only by {@see SetDefaultSharedAddress} (a transaction with row
 *    locking) — which stays exactly as it is; the database is a second,
 *    independent guard against a write that reaches this table any other
 *    way, never a replacement for the transaction.
 * 2. **`type` must belong to the set its own `owner_type` actually allows**
 *    ({@see AddressOwnerType::allowedAddressTypes()}). The original
 *    `shared_addresses_type_known` check accepted any of the five values for
 *    either owner kind — a `business_account` row could carry
 *    `type = 'pickup'` and the database would not object.
 * 3. **The division/district/upazila/(optional) union chain must actually
 *    nest in `bd_locations`.** Previously validated only at the HTTP
 *    boundary ({@see ValidBdLocationHierarchy}), never against a write that
 *    reaches this table any other way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE shared_addresses
                DROP CONSTRAINT shared_addresses_type_known,
                ADD CONSTRAINT shared_addresses_type_matches_owner CHECK (
                    (owner_type = 'business_account' AND type IN ('business', 'operational'))
                    OR (owner_type = 'supplier' AND type IN ('registered', 'pickup', 'return'))
                );

            CREATE UNIQUE INDEX shared_addresses_one_default_per_owner_type
                ON shared_addresses (owner_type, owner_id, type)
                WHERE is_default AND status = 'active';

            CREATE OR REPLACE FUNCTION feriwala_shared_address_location_hierarchy_is_valid() RETURNS trigger AS $$
            DECLARE
                division_type text;
                district_type text;
                district_parent bigint;
                upazila_type text;
                upazila_parent bigint;
                union_type text;
                union_parent bigint;
            BEGIN
                SELECT type INTO division_type FROM bd_locations WHERE id = NEW.division_id;
                IF division_type IS DISTINCT FROM 'division' THEN
                    RAISE EXCEPTION 'shared_addresses.division_id % is not a division', NEW.division_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT type, parent_id INTO district_type, district_parent FROM bd_locations WHERE id = NEW.district_id;
                IF district_type IS DISTINCT FROM 'district' OR district_parent IS DISTINCT FROM NEW.division_id THEN
                    RAISE EXCEPTION 'shared_addresses.district_id % is not a district of division %', NEW.district_id, NEW.division_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                SELECT type, parent_id INTO upazila_type, upazila_parent FROM bd_locations WHERE id = NEW.upazila_id;
                IF upazila_type IS DISTINCT FROM 'upazila' OR upazila_parent IS DISTINCT FROM NEW.district_id THEN
                    RAISE EXCEPTION 'shared_addresses.upazila_id % is not an upazila of district %', NEW.upazila_id, NEW.district_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                IF NEW.union_id IS NOT NULL THEN
                    SELECT type, parent_id INTO union_type, union_parent FROM bd_locations WHERE id = NEW.union_id;
                    IF union_type IS DISTINCT FROM 'union' OR union_parent IS DISTINCT FROM NEW.upazila_id THEN
                        RAISE EXCEPTION 'shared_addresses.union_id % is not a union of upazila %', NEW.union_id, NEW.upazila_id
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER shared_addresses_location_hierarchy_is_valid
                BEFORE INSERT OR UPDATE OF division_id, district_id, upazila_id, union_id ON shared_addresses
                FOR EACH ROW EXECUTE FUNCTION feriwala_shared_address_location_hierarchy_is_valid();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS shared_addresses_location_hierarchy_is_valid ON shared_addresses;
            DROP FUNCTION IF EXISTS feriwala_shared_address_location_hierarchy_is_valid();
            DROP INDEX IF EXISTS shared_addresses_one_default_per_owner_type;

            ALTER TABLE shared_addresses
                DROP CONSTRAINT IF EXISTS shared_addresses_type_matches_owner,
                ADD CONSTRAINT shared_addresses_type_known CHECK (
                    type IN ('business', 'operational', 'registered', 'pickup', 'return')
                );
        SQL);
    }
};
