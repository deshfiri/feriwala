<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One canonical Supplier-Product relationship: the Supplier Offer.
 *
 * A Supplier reaches a Product either through an approved listing or because
 * staff assigned it; both produce a `supplier_offers` row, and this records
 * which. The Supplier Base Price is the offer's existing `supplier_rate`; the
 * platform's own pricing stays on the Product and on `platform_rate`.
 *
 * The first, data-only `product_supplier_assignments` table is retired in the
 * same step — it was a second system for the same fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->string('origin', 24)->default('supplier_listing')->after('status');
            $table->foreignId('assigned_by')->nullable()->after('origin')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_by');
        });

        DB::unprepared(<<<'SQL'
            -- Everything that exists today came through a listing.
            UPDATE supplier_offers SET origin = 'supplier_listing';

            ALTER TABLE supplier_offers
                ADD CONSTRAINT supplier_offers_origin_known CHECK (origin IN ('supplier_listing', 'admin_assignment')),
                ADD CONSTRAINT supplier_offers_assignment_is_recorded CHECK (
                    (origin = 'admin_assignment') = (assigned_by IS NOT NULL AND assigned_at IS NOT NULL)
                );

            CREATE TRIGGER supplier_offers_origin_locked
                BEFORE UPDATE OF origin, assigned_by, assigned_at ON supplier_offers
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('origin', 'assigned_by', 'assigned_at');
        SQL);

        // One live offer per Supplier and Product/variation. Added only where
        // the data already respects it; the assignment action enforces the
        // same rule under a lock either way.
        $duplicates = DB::selectOne(<<<'SQL'
            SELECT count(*) AS c FROM (
                SELECT 1 FROM supplier_offers WHERE status = 'active'
                GROUP BY supplier_id, product_id, COALESCE(product_variant_id, 0)
                HAVING count(*) > 1
            ) d
        SQL);

        if ((int) $duplicates->c === 0) {
            DB::unprepared(<<<'SQL'
                CREATE UNIQUE INDEX supplier_offers_one_active_per_supplier_variation
                    ON supplier_offers (supplier_id, product_id, COALESCE(product_variant_id, 0))
                    WHERE status = 'active';
            SQL);
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_supplier_assignments_never_deleted ON product_supplier_assignments;
            DROP FUNCTION IF EXISTS feriwala_product_supplier_assignments_never_deleted();
        SQL);

        Schema::dropIfExists('product_supplier_assignments');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS supplier_offers_one_active_per_supplier_variation;
            DROP TRIGGER IF EXISTS supplier_offers_origin_locked ON supplier_offers;
            ALTER TABLE supplier_offers
                DROP CONSTRAINT IF EXISTS supplier_offers_assignment_is_recorded,
                DROP CONSTRAINT IF EXISTS supplier_offers_origin_known;
        SQL);

        Schema::table('supplier_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['origin', 'assigned_at']);
        });
    }
};
