<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product Sourcing Groups: staff-declared fulfilment equivalence.
 *
 * A group says "these catalogue products are commercially interchangeable when
 * fulfilling an order" -- Supplier A's "Regular Pants", Supplier B's "Men's
 * Cotton Pants" and the warehouse's "Men's Regular Pants" are one fulfilment
 * item. It is deliberately not a category (browsing taxonomy) and never
 * inferred from names, SKUs or descriptions.
 *
 * Membership is held at the **Central Product** level. Supplier offers and
 * warehouse stock items already point at a catalogue product/variation
 * (`supplier_offers.product_id`, `stock_items.product_id`), so they become
 * sources for a group through the product they sit on -- there is no second
 * allocation structure to keep in step with the first.
 *
 * Equivalence of products is not enough on its own. A variation of a member
 * product only fulfils a canonical variation through an explicit, active
 * mapping row (Black / M never matches Blue / L by label).
 *
 * Mapping rows follow `product_source_links`' conventions: identity columns
 * locked by trigger, never deleted (removed with a reason), and partial unique
 * indexes refusing a second active row for the same thing -- so a historical
 * order can always be explained by what was true when it was placed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sourcing_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 48)->unique();
            $table->string('name_en');
            $table->string('name_bn');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name_en']);
        });

        Schema::create('product_sourcing_group_products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sourcing_group_id')->constrained('product_sourcing_groups')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // The product whose variations define what an order requires.
            $table->boolean('is_canonical')->default(false);

            $table->string('status', 16)->default('active');
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->text('added_reason');
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['sourcing_group_id', 'status']);
        });

        Schema::create('product_sourcing_variant_mappings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('sourcing_group_id')->constrained('product_sourcing_groups')->restrictOnDelete();

            // The member side: a variation of a member product, or the product
            // itself when it has no variations.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            // The canonical side: a variation of the canonical product, or null
            // when the canonical product has none.
            $table->foreignId('canonical_product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->string('status', 16)->default('active');
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->text('added_reason');
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->index(['sourcing_group_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_sourcing_groups
                ADD CONSTRAINT product_sourcing_groups_code_format CHECK (code ~ '^[a-z0-9][a-z0-9_-]*$');

            ALTER TABLE product_sourcing_group_products
                ADD CONSTRAINT product_sourcing_group_products_status_known CHECK (status IN ('active', 'removed')),
                ADD CONSTRAINT product_sourcing_group_products_removal_is_recorded CHECK (
                    (status = 'removed') = (removed_by IS NOT NULL AND removed_at IS NOT NULL AND removal_reason IS NOT NULL)
                );

            ALTER TABLE product_sourcing_variant_mappings
                ADD CONSTRAINT product_sourcing_variant_mappings_status_known CHECK (status IN ('active', 'removed')),
                ADD CONSTRAINT product_sourcing_variant_mappings_removal_is_recorded CHECK (
                    (status = 'removed') = (removed_by IS NOT NULL AND removed_at IS NOT NULL AND removal_reason IS NOT NULL)
                );

            -- One product belongs to at most one group at a time; a group has
            -- exactly one canonical product while it has members.
            CREATE UNIQUE INDEX product_sourcing_group_products_one_active_group
                ON product_sourcing_group_products (product_id) WHERE status = 'active';

            CREATE UNIQUE INDEX product_sourcing_group_products_one_active_canonical
                ON product_sourcing_group_products (sourcing_group_id) WHERE status = 'active' AND is_canonical;

            -- One Supplier/warehouse variation maps to one canonical variation
            -- at a time: no ambiguity about what it fulfils.
            CREATE UNIQUE INDEX product_sourcing_variant_mappings_one_active
                ON product_sourcing_variant_mappings (product_id, COALESCE(product_variant_id, 0)) WHERE status = 'active';

            CREATE TRIGGER product_sourcing_group_products_locked_columns
                BEFORE UPDATE OF public_id, sourcing_group_id, product_id, is_canonical, added_by, added_reason
                ON product_sourcing_group_products
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'sourcing_group_id', 'product_id', 'is_canonical', 'added_by', 'added_reason'
                );

            CREATE TRIGGER product_sourcing_variant_mappings_locked_columns
                BEFORE UPDATE OF public_id, sourcing_group_id, product_id, product_variant_id,
                    canonical_product_variant_id, added_by, added_reason
                ON product_sourcing_variant_mappings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'sourcing_group_id', 'product_id', 'product_variant_id',
                    'canonical_product_variant_id', 'added_by', 'added_reason'
                );

            CREATE TRIGGER product_sourcing_groups_locked_columns
                BEFORE UPDATE OF public_id, code, created_by ON product_sourcing_groups
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'code', 'created_by');

            CREATE OR REPLACE FUNCTION feriwala_sourcing_rows_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'sourcing group records are never deleted: deactivate or remove them with a reason'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_sourcing_groups_never_deleted
                BEFORE DELETE ON product_sourcing_groups
                FOR EACH ROW EXECUTE FUNCTION feriwala_sourcing_rows_never_deleted();

            CREATE TRIGGER product_sourcing_group_products_never_deleted
                BEFORE DELETE ON product_sourcing_group_products
                FOR EACH ROW EXECUTE FUNCTION feriwala_sourcing_rows_never_deleted();

            CREATE TRIGGER product_sourcing_variant_mappings_never_deleted
                BEFORE DELETE ON product_sourcing_variant_mappings
                FOR EACH ROW EXECUTE FUNCTION feriwala_sourcing_rows_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_sourcing_variant_mappings_never_deleted ON product_sourcing_variant_mappings;
            DROP TRIGGER IF EXISTS product_sourcing_group_products_never_deleted ON product_sourcing_group_products;
            DROP TRIGGER IF EXISTS product_sourcing_groups_never_deleted ON product_sourcing_groups;
            DROP FUNCTION IF EXISTS feriwala_sourcing_rows_never_deleted();
        SQL);

        Schema::dropIfExists('product_sourcing_variant_mappings');
        Schema::dropIfExists('product_sourcing_group_products');
        Schema::dropIfExists('product_sourcing_groups');
    }
};
