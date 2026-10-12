<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domain verification and activation for the shared storefront (D30 §3, §4).
 *
 * Additive. The registration record staff already keep (D9) is untouched; this
 * adds whether the domain has been proven to point at us and whether it may
 * serve. Every existing domain starts **unverified**, so nothing begins serving
 * on the strength of a record made before verification existed.
 *
 *   - `verification_token` is published in the domain's DNS, so it is not a
 *     secret; it is still never shown to anybody but staff.
 *   - A name is held by one website while it is verified, active or suspended
 *     — a second partial unique index beside the registration one — so a
 *     lapsed registration cannot hand a proven name to somebody else.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE website_domains
                ADD COLUMN routing_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
                ADD COLUMN verification_token VARCHAR(64) NULL,
                ADD COLUMN verification_issued_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                ADD COLUMN verification_checked_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                ADD COLUMN verification_error VARCHAR(255) NULL,
                ADD COLUMN verified_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                ADD COLUMN activated_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                ADD COLUMN suspended_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                ADD COLUMN tls_authorized_at TIMESTAMP(0) WITHOUT TIME ZONE NULL,
                ADD CONSTRAINT website_domains_routing_status_known
                    CHECK (routing_status IN ('unverified', 'verified', 'active', 'suspended')),
                ADD CONSTRAINT website_domains_proven_before_serving
                    CHECK (routing_status = 'unverified' OR verified_at IS NOT NULL);

            CREATE UNIQUE INDEX website_domains_proven_name_unique
                ON website_domains (domain)
                WHERE routing_status IN ('verified', 'active', 'suspended');
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS website_domains_proven_name_unique;

            ALTER TABLE website_domains
                DROP CONSTRAINT website_domains_proven_before_serving,
                DROP CONSTRAINT website_domains_routing_status_known,
                DROP COLUMN tls_authorized_at,
                DROP COLUMN suspended_at,
                DROP COLUMN activated_at,
                DROP COLUMN verified_at,
                DROP COLUMN verification_error,
                DROP COLUMN verification_checked_at,
                DROP COLUMN verification_issued_at,
                DROP COLUMN verification_token,
                DROP COLUMN routing_status;
        SQL);
    }
};
