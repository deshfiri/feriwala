<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects two guards on the Supplier listing tables that were stricter than
 * the lifecycle they protect (D25, P13-11). A new migration rather than an
 * edit to `take_supplier_listings`, which has already been committed.
 *
 * 1. `connected_product_id` was in the *locked* column set, which refuses any
 *    change including the first write — but a listing is connected to its
 *    Central Product exactly once, at decision time, after the row exists. It
 *    is now **set-once**: NULL may become a product, and a written value can
 *    never change or be cleared.
 *
 * 2. Listing items were never deletable, so a Supplier could not remove a
 *    variation from a draft. Items may now be deleted, but only while their
 *    listing is still a draft — from the moment it is submitted every item is
 *    part of what a reviewer saw and decided, and stays. Listings themselves
 *    are still never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_product_listings_locked_columns ON supplier_product_listings;

            CREATE TRIGGER supplier_product_listings_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id'
                );

            CREATE OR REPLACE FUNCTION feriwala_supplier_listing_connection_is_set_once() RETURNS trigger AS $$
            BEGIN
                IF OLD.connected_product_id IS NOT NULL
                    AND NEW.connected_product_id IS DISTINCT FROM OLD.connected_product_id THEN
                    RAISE EXCEPTION 'supplier_product_listings.connected_product_id is set once'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_product_listings_connection_set_once
                BEFORE UPDATE OF connected_product_id ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listing_connection_is_set_once();

            DROP TRIGGER IF EXISTS supplier_product_listing_items_no_delete ON supplier_product_listing_items;

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

            CREATE TRIGGER supplier_product_listing_items_deletable_only_in_draft
                BEFORE DELETE ON supplier_product_listing_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listing_items_deletable_only_in_draft();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_product_listing_items_deletable_only_in_draft ON supplier_product_listing_items;
            DROP FUNCTION IF EXISTS feriwala_supplier_listing_items_deletable_only_in_draft();

            CREATE TRIGGER supplier_product_listing_items_no_delete
                BEFORE DELETE ON supplier_product_listing_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listings_are_never_deleted();

            DROP TRIGGER IF EXISTS supplier_product_listings_connection_set_once ON supplier_product_listings;
            DROP FUNCTION IF EXISTS feriwala_supplier_listing_connection_is_set_once();
            DROP TRIGGER IF EXISTS supplier_product_listings_locked_columns ON supplier_product_listings;

            CREATE TRIGGER supplier_product_listings_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id, connected_product_id ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'connected_product_id'
                );
        SQL);
    }
};
