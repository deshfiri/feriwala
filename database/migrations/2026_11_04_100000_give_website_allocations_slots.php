<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One allocation per permitted website, not one per purchase (D30 §9).
 *
 * A package that allows two websites gives two allocation slots, each with its
 * own website and its own domain and hosting entitlement. Additive: every
 * existing allocation becomes slot 1 of its purchase, which is exactly what it
 * already was, and nothing about it is rewritten.
 *
 * The duplicate guard moves from "one row per purchase" to "one row per purchase
 * and slot", so a repeated callback still cannot allocate twice. A website is
 * held by at most one slot of a purchase.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE website_allocations
                ADD COLUMN slot SMALLINT NOT NULL DEFAULT 1,
                ADD CONSTRAINT website_allocations_slot_positive CHECK (slot >= 1);

            ALTER TABLE website_allocations DROP CONSTRAINT website_allocations_user_package_id_unique;

            CREATE UNIQUE INDEX website_allocations_one_per_slot
                ON website_allocations (user_package_id, slot);

            CREATE UNIQUE INDEX website_allocations_one_slot_per_website
                ON website_allocations (user_package_id, website_id)
                WHERE website_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        // Narrowing back to one row per purchase would have to delete the
        // extra slots. It refuses instead: allocations are never discarded.
        if (DB::table('website_allocations')->where('slot', '>', 1)->exists()) {
            throw new RuntimeException('Website allocations beyond slot 1 exist; they cannot be rolled back without losing them.');
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS website_allocations_one_slot_per_website;
            DROP INDEX IF EXISTS website_allocations_one_per_slot;

            ALTER TABLE website_allocations
                ADD CONSTRAINT website_allocations_user_package_id_unique UNIQUE (user_package_id);

            ALTER TABLE website_allocations
                DROP CONSTRAINT website_allocations_slot_positive,
                DROP COLUMN slot;
        SQL);
    }
};
