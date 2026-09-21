<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Offers: one Supplier's independently-priced, independently-stocked
 * claim on one catalogue product or variation (D25, P13-14, P13-15).
 *
 * **One product variation may have many offers.** Each retains its own
 * Supplier identity, its own Supplier Rate, its own availability, and can be
 * activated or suspended without touching any other Supplier's offer on the
 * same variation — approving a second Supplier's listing for a product a
 * first Supplier already supplies must never overwrite the first one's rate
 * or stock. For the beta, Admin chooses which one offer is *preferred* — used
 * for Client/Partner purchasing — rather than the system picking automatically
 * (no cheapest-supplier algorithm).
 *
 * **Two rates, two audiences.** The Supplier Rate is what was agreed with the
 * Supplier; the Platform Rate is what Feriwala charges a Client/Partner. Every
 * change to either is a new, effective-dated row in
 * `supplier_offer_price_changes` — never an edit to a past one — so an order
 * priced last month keeps citing the rate that was actually in force then.
 * `supplier_offers` carries only the **current** figures, denormalised for
 * fast reads; the price-change table is the append-only ledger of how they
 * got there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();

            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('originating_listing_item_id')->nullable()
                ->constrained('supplier_product_listing_items')->nullOnDelete();

            $table->string('status', 16);
            $table->boolean('is_preferred')->default(false);
            $table->boolean('wholesale_enabled')->default(false);
            $table->boolean('dropshipping_enabled')->default(false);

            $table->bigInteger('supplier_rate_minor');
            $table->bigInteger('platform_rate_minor');
            $table->char('currency_code', 3)->default('BDT');

            $table->foreignId('activated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('suspended_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('suspended_at')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'product_variant_id']);
            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_offer_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_offer_id')->constrained()->restrictOnDelete();

            $table->bigInteger('supplier_rate_minor');
            $table->bigInteger('platform_rate_minor');
            $table->char('currency_code', 3);
            $table->timestamp('effective_from');

            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason');

            $table->timestamp('created_at');

            $table->index(['supplier_offer_id', 'effective_from']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_offers
                ADD CONSTRAINT supplier_offers_status_known CHECK (status IN ('active', 'suspended')),
                ADD CONSTRAINT supplier_offers_rates_not_negative CHECK (
                    supplier_rate_minor >= 0 AND platform_rate_minor >= 0
                ),
                -- The rule the whole pricing model exists to enforce: the platform
                -- never sells for less than it pays the Supplier.
                ADD CONSTRAINT supplier_offers_platform_rate_not_below_supplier_rate CHECK (
                    platform_rate_minor >= supplier_rate_minor
                ),
                ADD CONSTRAINT supplier_offers_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$');

            -- Admin's chosen preferred offer, at most one per product variation.
            -- COALESCE folds a NULL variant (a base product with no variation) to
            -- a comparable value — Postgres treats two NULLs in a unique index as
            -- distinct, which would otherwise let every base-product offer claim
            -- to be preferred at once.
            CREATE UNIQUE INDEX supplier_offers_one_preferred_per_variation
                ON supplier_offers (product_id, COALESCE(product_variant_id, 0))
                WHERE is_preferred;

            -- One listing item becomes at most one offer.
            CREATE UNIQUE INDEX supplier_offers_one_per_listing_item
                ON supplier_offers (originating_listing_item_id)
                WHERE originating_listing_item_id IS NOT NULL;

            /*
             * An offer's variation must actually belong to its product — the
             * cross-catalogue mismatch a hand-typed id could otherwise produce.
             */
            CREATE OR REPLACE FUNCTION feriwala_supplier_offer_variant_matches_product() RETURNS trigger AS $$
            BEGIN
                IF NEW.product_variant_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM product_variants
                    WHERE id = NEW.product_variant_id AND product_id = NEW.product_id
                ) THEN
                    RAISE EXCEPTION 'a supplier offer''s variant must belong to its product'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_offers_variant_matches_product
                BEFORE INSERT OR UPDATE OF product_id, product_variant_id ON supplier_offers
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_offer_variant_matches_product();

            CREATE TRIGGER supplier_offers_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id, product_id, product_variant_id, originating_listing_item_id
                ON supplier_offers
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'product_id', 'product_variant_id', 'originating_listing_item_id'
                );

            ALTER TABLE supplier_offer_price_changes
                ADD CONSTRAINT supplier_offer_price_changes_rates_not_negative CHECK (
                    supplier_rate_minor >= 0 AND platform_rate_minor >= 0
                ),
                ADD CONSTRAINT supplier_offer_price_changes_platform_not_below_supplier CHECK (
                    platform_rate_minor >= supplier_rate_minor
                ),
                ADD CONSTRAINT supplier_offer_price_changes_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT supplier_offer_price_changes_reason_present CHECK (length(btrim(reason)) > 0);

            -- Append-only: approved pricing history is never edited or destroyed.
            CREATE OR REPLACE FUNCTION feriwala_supplier_offer_price_changes_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'supplier_offer_price_changes is append-only'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_offer_price_changes_no_update
                BEFORE UPDATE ON supplier_offer_price_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_offer_price_changes_immutable();

            CREATE TRIGGER supplier_offer_price_changes_no_delete
                BEFORE DELETE ON supplier_offer_price_changes
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_offer_price_changes_immutable();
        SQL);

        // The FK from listing items to offers, added now that supplier_offers exists.
        Schema::table('supplier_product_listing_items', function (Blueprint $table) {
            $table->foreign('supplier_offer_id')->references('id')->on('supplier_offers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_product_listing_items', function (Blueprint $table) {
            $table->dropForeign(['supplier_offer_id']);
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_offer_price_changes_no_delete ON supplier_offer_price_changes;
            DROP TRIGGER IF EXISTS supplier_offer_price_changes_no_update ON supplier_offer_price_changes;
            DROP FUNCTION IF EXISTS feriwala_supplier_offer_price_changes_immutable();
            DROP TRIGGER IF EXISTS supplier_offers_locked_columns ON supplier_offers;
            DROP TRIGGER IF EXISTS supplier_offers_variant_matches_product ON supplier_offers;
            DROP FUNCTION IF EXISTS feriwala_supplier_offer_variant_matches_product();
        SQL);

        Schema::dropIfExists('supplier_offer_price_changes');
        Schema::dropIfExists('supplier_offers');
    }
};
