<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Product Listing Requests: proposals, never publications (D25,
 * contract-equivalent §11/§12, P13-11).
 *
 * A listing is what an approved Supplier proposes; it never writes to
 * `products` directly, and submitting one never publishes anything. Admin or
 * an Authorized User reviews it and either connects it to an existing central
 * product, or creates a new one through the existing product architecture —
 * both are application-level actions using the catalogue this migration never
 * touches. §12 continues to hold: only Feriwala creates products.
 *
 * A listing carries one or more **items** — one per proposed variation — so a
 * single request can be partially approved: some variations connected and
 * priced, others rejected or sent back for correction, without touching the
 * ones already decided (§18.2-equivalent partial approval).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_listings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('status', 32);

            $table->string('product_name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('category_suggestion')->nullable();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('brand_suggestion')->nullable();
            $table->text('supplier_note')->nullable();

            // Stored file references: [{disk, path, original_name, mime_type, size_bytes}].
            // The files themselves live on the private `supplier-media` disk,
            // read only through SupplierListingFileStore.
            $table->jsonb('images')->nullable();
            $table->jsonb('documents')->nullable();

            // Set once Admin connects this listing to the central catalogue —
            // never written by anything a Supplier can reach.
            $table->foreignId('connected_product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('decision_note')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_product_listing_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_product_listing_id')->constrained()->restrictOnDelete();

            $table->string('variant_label')->nullable();
            $table->string('supplier_sku', 64);
            $table->bigInteger('supplier_rate_minor');
            $table->char('currency_code', 3)->default('BDT');
            $table->unsignedInteger('available_quantity');
            $table->unsignedInteger('minimum_supply_quantity')->default(1);
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->text('warranty')->nullable();
            $table->text('return_conditions')->nullable();

            $table->string('status', 32);
            $table->text('decision_note')->nullable();

            // Filled in only on approval, and only by the review action.
            $table->foreignId('connected_product_variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();
            $table->foreignId('supplier_offer_id')->nullable()->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('supplier_product_listing_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_listing_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['supplier_product_listing_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listings
                ADD CONSTRAINT supplier_product_listings_status_known CHECK (
                    status IN (
                        'draft', 'submitted', 'under_review', 'correction_required',
                        'approved', 'partially_approved', 'rejected', 'suspended', 'archived'
                    )
                ),
                ADD CONSTRAINT supplier_product_listings_name_present CHECK (length(btrim(product_name)) > 0);

            ALTER TABLE supplier_product_listing_items
                ADD CONSTRAINT supplier_product_listing_items_status_known CHECK (
                    status IN ('pending', 'approved', 'rejected', 'correction_required')
                ),
                ADD CONSTRAINT supplier_product_listing_items_rate_not_negative CHECK (supplier_rate_minor >= 0),
                ADD CONSTRAINT supplier_product_listing_items_quantities_not_negative CHECK (
                    available_quantity >= 0 AND minimum_supply_quantity >= 1
                    AND minimum_supply_quantity <= GREATEST(available_quantity, 1)
                ),
                ADD CONSTRAINT supplier_product_listing_items_currency_is_iso CHECK (currency_code ~ '^[A-Z]{3}$');

            /*
             * An item belongs to the listing of its own Supplier — the ownership
             * invariant the batch requires be database-enforced, not merely
             * checked in a query.
             */
            CREATE OR REPLACE FUNCTION feriwala_supplier_listing_item_matches_listing() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM supplier_product_listings WHERE id = NEW.supplier_product_listing_id
                ) THEN
                    RAISE EXCEPTION 'a listing item must belong to an existing listing'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_product_listing_items_belong_to_listing
                BEFORE INSERT ON supplier_product_listing_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listing_item_matches_listing();

            CREATE TRIGGER supplier_product_listings_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id, connected_product_id ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'connected_product_id'
                );

            CREATE TRIGGER supplier_product_listing_items_locked_columns
                BEFORE UPDATE OF public_id, supplier_product_listing_id ON supplier_product_listing_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'supplier_product_listing_id'
                );

            ALTER TABLE supplier_product_listing_status_history
                ADD CONSTRAINT supplier_product_listing_status_history_source_known CHECK (
                    source IN ('supplier', 'staff', 'system')
                ),
                ADD CONSTRAINT supplier_product_listing_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER supplier_product_listing_status_history_no_update
                BEFORE UPDATE ON supplier_product_listing_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER supplier_product_listing_status_history_no_delete
                BEFORE DELETE ON supplier_product_listing_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE OR REPLACE FUNCTION feriwala_supplier_listings_are_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is never deleted; a rejected listing stays rejected', TG_TABLE_NAME
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_product_listings_no_delete
                BEFORE DELETE ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listings_are_never_deleted();

            CREATE TRIGGER supplier_product_listing_items_no_delete
                BEFORE DELETE ON supplier_product_listing_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listings_are_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_product_listing_items_no_delete ON supplier_product_listing_items;
            DROP TRIGGER IF EXISTS supplier_product_listings_no_delete ON supplier_product_listings;
            DROP FUNCTION IF EXISTS feriwala_supplier_listings_are_never_deleted();
            DROP TRIGGER IF EXISTS supplier_product_listing_status_history_no_delete ON supplier_product_listing_status_history;
            DROP TRIGGER IF EXISTS supplier_product_listing_status_history_no_update ON supplier_product_listing_status_history;
            DROP TRIGGER IF EXISTS supplier_product_listing_items_locked_columns ON supplier_product_listing_items;
            DROP TRIGGER IF EXISTS supplier_product_listings_locked_columns ON supplier_product_listings;
            DROP TRIGGER IF EXISTS supplier_product_listing_items_belong_to_listing ON supplier_product_listing_items;
            DROP FUNCTION IF EXISTS feriwala_supplier_listing_item_matches_listing();
        SQL);

        Schema::dropIfExists('supplier_product_listing_status_history');
        Schema::dropIfExists('supplier_product_listing_items');
        Schema::dropIfExists('supplier_product_listings');
    }
};
