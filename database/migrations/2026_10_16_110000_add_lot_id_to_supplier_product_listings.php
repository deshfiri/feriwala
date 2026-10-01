<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links each Supplier product entry to the lot it was drafted in (Supplier
 * Bulk Product Listing batch).
 *
 * Nullable, not because a lot is optional going forward, but because rows
 * created before this migration have no lot to belong to and are not being
 * backfilled into a synthetic one -- they stay exactly the single-product
 * listings they always were. Every new listing created through the lot
 * workspace sets this on creation, and it joins the already-locked column
 * set immediately after, so a listing can never be moved into a different
 * lot once written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_product_listings', function (Blueprint $table) {
            $table->foreignId('lot_id')->nullable()->after('supplier_id')
                ->constrained('supplier_product_listing_lots')->restrictOnDelete();

            $table->index(['lot_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_product_listings_locked_columns ON supplier_product_listings;

            CREATE TRIGGER supplier_product_listings_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id, lot_id ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id', 'lot_id'
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS supplier_product_listings_locked_columns ON supplier_product_listings;

            CREATE TRIGGER supplier_product_listings_locked_columns
                BEFORE UPDATE OF public_id, reference, supplier_id ON supplier_product_listings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'reference', 'supplier_id'
                );
        SQL);

        Schema::table('supplier_product_listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lot_id');
        });
    }
};
