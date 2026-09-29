<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A staff-confirmed, audited assertion that a Supplier offer or a warehouse
 * stock item -- whatever central product/variation it is itself catalogued
 * under -- fulfils orders for a *different* ordered product/variation.
 *
 * `supplier_offers.product_id`/`stock_items.product_id` are already hard,
 * locked FKs to the central catalogue (a Supplier offer or a stock item
 * always names one specific product/variation) -- the exact-match case
 * (`AllocationSourceCandidates`' existing `WHERE product_id = ? AND
 * product_variant_id = ?`) needs no row here at all; it is trivially the same
 * item. This table exists only for the cross-catalogue case: two entries
 * that are genuinely the same real-world fulfilment item under two different
 * catalogue rows (a duplicate listing, a Supplier's own SKU, a rebrand),
 * where staff judgement -- not a query -- is what makes them the same thing.
 *
 * Modelled on `order_item_allocations`' own conventions rather than a bare
 * pivot table, because a wrong link here reaches real money (which Supplier
 * gets paid for which order) the same way an allocation does: identity
 * columns locked by trigger the moment the row exists, never deleted (only
 * revoked), and a partial unique index refusing to duplicate a link that
 * already exists for the same ordered product/variation and the same
 * source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_source_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('ordered_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('ordered_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->string('source_type', 24);
            $table->foreignId('warehouse_stock_item_id')->nullable()->constrained('stock_items')->restrictOnDelete();
            $table->foreignId('supplier_offer_id')->nullable()->constrained('supplier_offers')->restrictOnDelete();

            $table->string('status', 16)->default('active');

            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->text('confirmation_reason');

            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();

            $table->index(['ordered_product_id', 'ordered_product_variant_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_source_links
                ADD CONSTRAINT product_source_links_source_type_known CHECK (
                    source_type IN ('warehouse', 'supplier_offer')
                ),
                ADD CONSTRAINT product_source_links_status_known CHECK (
                    status IN ('active', 'revoked')
                ),
                ADD CONSTRAINT product_source_links_source_is_coherent CHECK (
                    (source_type = 'warehouse' AND warehouse_stock_item_id IS NOT NULL AND supplier_offer_id IS NULL)
                    OR
                    (source_type = 'supplier_offer' AND supplier_offer_id IS NOT NULL AND warehouse_stock_item_id IS NULL)
                ),
                ADD CONSTRAINT product_source_links_revocation_is_recorded CHECK (
                    (status = 'revoked') = (revoked_by IS NOT NULL AND revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
                );

            -- Postgres treats null as distinct from null, so a partial index
            -- per source type -- the same shape `stock_items_product_unique`/
            -- `stock_items_variant_unique` already use.
            CREATE UNIQUE INDEX product_source_links_one_active_warehouse_link
                ON product_source_links (ordered_product_id, COALESCE(ordered_product_variant_id, 0), warehouse_stock_item_id)
                WHERE status = 'active' AND source_type = 'warehouse';

            CREATE UNIQUE INDEX product_source_links_one_active_supplier_link
                ON product_source_links (ordered_product_id, COALESCE(ordered_product_variant_id, 0), supplier_offer_id)
                WHERE status = 'active' AND source_type = 'supplier_offer';

            -- A link asserting a source fulfils a product it is already
            -- directly catalogued under is not what this table is for --
            -- that case is already eligible with no confirmation at all.
            CREATE OR REPLACE FUNCTION feriwala_product_source_link_is_cross_catalogue() RETURNS trigger AS $$
            DECLARE
                source_product_id bigint;
                source_variant_id bigint;
            BEGIN
                IF NEW.source_type = 'warehouse' THEN
                    SELECT product_id, product_variant_id INTO source_product_id, source_variant_id
                        FROM stock_items WHERE id = NEW.warehouse_stock_item_id;
                ELSE
                    SELECT product_id, product_variant_id INTO source_product_id, source_variant_id
                        FROM supplier_offers WHERE id = NEW.supplier_offer_id;
                END IF;

                IF source_product_id = NEW.ordered_product_id
                    AND source_variant_id IS NOT DISTINCT FROM NEW.ordered_product_variant_id THEN
                    RAISE EXCEPTION 'product_source_links: source already is this exact product/variation -- no link needed'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_source_links_is_cross_catalogue
                BEFORE INSERT ON product_source_links
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_source_link_is_cross_catalogue();

            CREATE TRIGGER product_source_links_locked_columns
                BEFORE UPDATE OF
                    public_id, ordered_product_id, ordered_product_variant_id,
                    source_type, warehouse_stock_item_id, supplier_offer_id,
                    confirmed_by, confirmed_at, confirmation_reason
                ON product_source_links
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'ordered_product_id', 'ordered_product_variant_id',
                    'source_type', 'warehouse_stock_item_id', 'supplier_offer_id',
                    'confirmed_by', 'confirmed_at', 'confirmation_reason'
                );

            CREATE OR REPLACE FUNCTION feriwala_product_source_links_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a product source link is never deleted: revoke it instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_source_links_never_deleted
                BEFORE DELETE ON product_source_links
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_source_links_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_source_links_never_deleted ON product_source_links;
            DROP FUNCTION IF EXISTS feriwala_product_source_links_never_deleted();
            DROP TRIGGER IF EXISTS product_source_links_locked_columns ON product_source_links;
            DROP TRIGGER IF EXISTS product_source_links_is_cross_catalogue ON product_source_links;
            DROP FUNCTION IF EXISTS feriwala_product_source_link_is_cross_catalogue();
        SQL);

        Schema::dropIfExists('product_source_links');
    }
};
