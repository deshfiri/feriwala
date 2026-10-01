<?php

use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Supplier\SupplierListingMediaStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real image upload for a Supplier product entry (Supplier Bulk Product
 * Listing batch) -- the listing/listing item `images`/`documents` jsonb
 * columns were metadata-only placeholders with no store or upload path ever
 * built behind them; this table and {@see SupplierListingMediaStore}
 * are that missing piece, shaped like {@see ProductMedia}
 * but on a private disk -- these are pre-approval proposals, not public
 * storefront assets.
 *
 * A primary image is required per product entry (enforced again at the
 * application layer before submission, since a partial unique index alone
 * only stops a *second* primary, not a *missing* one). `alt_text` is
 * NOT NULL because the batch's spec requires it always, not only when
 * someone remembers to type it.
 *
 * Immutable once the parent listing has left `draft`/`correction_required`:
 * this is the database half of "images submitted for review must be
 * snapshotted so later Supplier edits cannot change what staff originally
 * reviewed" -- the other half is the full media list captured into the
 * submission's own {@see AuditEntry}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_listing_media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('supplier_product_listing_id')->constrained()->restrictOnDelete();

            // Optional: a variant-specific override image, on the same
            // product entry's variation row.
            $table->foreignId('supplier_product_listing_item_id')->nullable()
                ->constrained('supplier_product_listing_items')->cascadeOnDelete();

            $table->string('role', 16);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt_text', 255);
            $table->unsignedInteger('position');

            $table->timestamps();

            $table->index(['supplier_product_listing_id', 'position']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_listing_media
                ADD CONSTRAINT supplier_listing_media_role_known CHECK (role IN ('primary', 'gallery')),
                ADD CONSTRAINT supplier_listing_media_alt_text_present CHECK (length(btrim(alt_text)) > 0),
                ADD CONSTRAINT supplier_listing_media_size_positive CHECK (size_bytes > 0),
                ADD CONSTRAINT supplier_listing_media_position_positive CHECK (position >= 1);

            /*
             * One primary image per product entry -- variant-specific media
             * (supplier_product_listing_item_id IS NOT NULL) never counts as
             * the entry's own primary, since it illustrates one variation,
             * not the listing as a whole.
             */
            CREATE UNIQUE INDEX supplier_listing_media_one_primary_per_listing
                ON supplier_listing_media (supplier_product_listing_id)
                WHERE role = 'primary' AND supplier_product_listing_item_id IS NULL;

            CREATE TRIGGER supplier_listing_media_locked_columns
                BEFORE UPDATE OF public_id, supplier_product_listing_id ON supplier_listing_media
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'supplier_product_listing_id'
                );

            CREATE OR REPLACE FUNCTION feriwala_supplier_listing_media_locked_after_submission() RETURNS trigger AS $$
            DECLARE
                listing_status text;
                target_id bigint := COALESCE(NEW.supplier_product_listing_id, OLD.supplier_product_listing_id);
            BEGIN
                SELECT status INTO listing_status FROM supplier_product_listings WHERE id = target_id;

                IF listing_status NOT IN ('draft', 'correction_required') THEN
                    RAISE EXCEPTION 'listing media cannot be changed once its listing has been submitted for review'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER supplier_listing_media_update_locked_after_submission
                BEFORE UPDATE ON supplier_listing_media
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listing_media_locked_after_submission();

            CREATE TRIGGER supplier_listing_media_delete_locked_after_submission
                BEFORE DELETE ON supplier_listing_media
                FOR EACH ROW EXECUTE FUNCTION feriwala_supplier_listing_media_locked_after_submission();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_listing_media_delete_locked_after_submission ON supplier_listing_media;
            DROP TRIGGER IF EXISTS supplier_listing_media_update_locked_after_submission ON supplier_listing_media;
            DROP FUNCTION IF EXISTS feriwala_supplier_listing_media_locked_after_submission();
            DROP TRIGGER IF EXISTS supplier_listing_media_locked_columns ON supplier_listing_media;
        SQL);

        Schema::dropIfExists('supplier_listing_media');
    }
};
