<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which Suppliers actually have which Product: a staff-made record that a
 * Supplier holds a Product, kept so the real sources of an item are known —
 * the Suppliers Feriwala would buy it from.
 *
 * It says nothing about price. Rates, overrides, stock and approval stay with
 * the Supplier listing system and its offers, exactly as before; this is only
 * the fact "this Supplier has this item". A Product may have many Suppliers and
 * a Supplier many Products, once each at a time.
 *
 * Follows the Same Product links' conventions: identity columns locked by
 * trigger and nothing ever deleted — removing an assignment records who, when
 * and why, and keeps the row as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            $table->string('status', 16)->default('active');

            // The Supplier's own name or code for it, when staff know it.
            $table->text('supplier_reference')->nullable();

            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->text('assign_reason')->nullable();

            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();

            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['product_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_supplier_assignments
                ADD CONSTRAINT product_supplier_assignments_status_known CHECK (status IN ('active', 'removed')),
                ADD CONSTRAINT product_supplier_assignments_removal_is_recorded CHECK (
                    (status = 'removed') = (removed_by IS NOT NULL AND removed_at IS NOT NULL)
                );

            CREATE UNIQUE INDEX product_supplier_assignments_one_active
                ON product_supplier_assignments (product_id, supplier_id) WHERE status = 'active';

            CREATE TRIGGER product_supplier_assignments_locked_columns
                BEFORE UPDATE OF public_id, product_id, supplier_id, assigned_by, assigned_at
                ON product_supplier_assignments
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'product_id', 'supplier_id', 'assigned_by', 'assigned_at'
                );

            CREATE OR REPLACE FUNCTION feriwala_product_supplier_assignments_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'a product supplier assignment is never deleted: remove it instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_supplier_assignments_never_deleted
                BEFORE DELETE ON product_supplier_assignments
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_supplier_assignments_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_supplier_assignments_never_deleted ON product_supplier_assignments;
            DROP FUNCTION IF EXISTS feriwala_product_supplier_assignments_never_deleted();
        SQL);

        Schema::dropIfExists('product_supplier_assignments');
    }
};
