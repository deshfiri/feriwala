<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens when a Supplier product listing's items may be deleted (Supplier
 * Bulk Product Listing batch).
 *
 * `2026_10_02_150000_relax_supplier_listing_guards` made items deletable
 * only while their listing is `draft`. The batch's own spec asks for
 * "Draft or Returned" -- a listing sent back for correction
 * (`correction_required`, this codebase's name for what the batch calls
 * "Returned") is exactly as editable as a draft while a Supplier reworks it,
 * so its items must be removable too. A new migration rather than an edit to
 * the one that already shipped this guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_supplier_listing_items_deletable_only_in_draft() RETURNS trigger AS $$
            DECLARE
                listing_status text;
            BEGIN
                SELECT status INTO listing_status FROM supplier_product_listings
                    WHERE id = OLD.supplier_product_listing_id;

                IF listing_status NOT IN ('draft', 'correction_required') THEN
                    RAISE EXCEPTION 'a listing item can only be deleted while its listing is a draft or returned for correction'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION feriwala_supplier_listing_items_deletable_only_in_draft() RETURNS trigger AS $$
            DECLARE
                listing_status text;
            BEGIN
                SELECT status INTO listing_status FROM supplier_product_listings
                    WHERE id = OLD.supplier_product_listing_id;

                IF listing_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'a listing item can only be deleted while its listing is a draft'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
