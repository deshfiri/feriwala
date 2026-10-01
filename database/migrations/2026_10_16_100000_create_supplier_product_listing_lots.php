<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Supplier Bulk Product Listing lot: the wrapper a Supplier drafts many
 * product entries into and submits once (D25, Supplier Bulk Product Listing
 * batch).
 *
 * A `SupplierProductListing` row is already "one item" in the batch's own
 * vocabulary — one proposed product, itself already capable of carrying many
 * variations as its own `items`. This table only adds the missing layer
 * above it: grouping several `SupplierProductListing` rows so they can be
 * drafted together and reviewed/tracked as one submission, without changing
 * anything about how a single product entry or its variations already work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_listing_lots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('reference', 32)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->string('status', 32);
            $table->string('title')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
        });

        Schema::create('supplier_product_listing_lot_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_product_listing_lot_id')->constrained()->restrictOnDelete();

            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->string('source', 16);

            $table->text('reason')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('public_note')->nullable();

            $table->index(['supplier_product_listing_lot_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE supplier_product_listing_lots
                ADD CONSTRAINT supplier_product_listing_lots_status_known CHECK (
                    status IN (
                        'draft', 'submitted', 'under_review', 'partially_approved',
                        'approved', 'returned', 'rejected', 'closed'
                    )
                );

            CREATE TRIGGER supplier_product_listing_lots_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id ON supplier_product_listing_lots
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id'
                );

            ALTER TABLE supplier_product_listing_lot_status_history
                ADD CONSTRAINT supplier_product_listing_lot_status_history_source_known CHECK (
                    source IN ('supplier', 'staff', 'system')
                ),
                ADD CONSTRAINT supplier_product_listing_lot_status_history_is_a_change CHECK (
                    previous_status IS DISTINCT FROM new_status
                );

            CREATE TRIGGER supplier_product_listing_lot_status_history_no_update
                BEFORE UPDATE ON supplier_product_listing_lot_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            CREATE TRIGGER supplier_product_listing_lot_status_history_no_delete
                BEFORE DELETE ON supplier_product_listing_lot_status_history
                FOR EACH ROW EXECUTE FUNCTION feriwala_status_history_is_append_only();

            /*
             * A lot with no items yet is discardable the same way a draft
             * listing is (ArchiveSupplierListingLotDraft refuses this unless
             * the lot is empty) -- once it holds any item, the item rows
             * themselves are what "never deleted" already governs, so the
             * lot never needs its own no-delete trigger.
             */
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_product_listing_lot_status_history_no_delete ON supplier_product_listing_lot_status_history;
            DROP TRIGGER IF EXISTS supplier_product_listing_lot_status_history_no_update ON supplier_product_listing_lot_status_history;
            DROP TRIGGER IF EXISTS supplier_product_listing_lots_locked_columns ON supplier_product_listing_lots;
        SQL);

        Schema::dropIfExists('supplier_product_listing_lot_status_history');
        Schema::dropIfExists('supplier_product_listing_lots');
    }
};
