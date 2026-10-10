<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Same Product" links: a staff-confirmed assertion that two Product records
 * are the same physical Product, listed separately by different Suppliers.
 *
 * A link is a plain edge between two independent Product records. There is no
 * master, parent or group: any Product may link to any other, links are
 * bidirectional, and the network a Product belongs to is whatever it can reach
 * through active links. Each pair is stored once, with the lower Product id in
 * `product_a_id`, so "A-B" and "B-A" are the same row by construction and a
 * partial unique index refuses a second active one.
 *
 * Product-level compatibility is not variation-level compatibility. Two linked
 * Products with variations are only interchangeable variation by variation,
 * through explicit mapping rows (a null side means "the Product itself, which
 * has no variations").
 *
 * Both tables follow `product_source_links`' conventions: identity columns
 * locked by trigger, nothing ever deleted -- an unlink records who, when and
 * why and leaves the row as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_same_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_a_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_b_id')->constrained('products')->restrictOnDelete();

            $table->string('status', 16)->default('active');

            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('linked_at');
            $table->text('link_reason')->nullable();

            $table->foreignId('unlinked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('unlinked_at')->nullable();
            $table->text('unlink_reason')->nullable();

            $table->timestamps();

            $table->index('product_a_id');
            $table->index('product_b_id');
        });

        Schema::create('product_link_variant_mappings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_link_id')->constrained('product_same_links')->restrictOnDelete();

            // A variation of the link's `product_a`, or null for that Product
            // itself when it has no variations; likewise for side B.
            $table->foreignId('variant_a_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('variant_b_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->string('status', 16)->default('active');

            $table->foreignId('mapped_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('mapped_at');
            $table->text('map_reason')->nullable();

            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();

            $table->timestamps();

            $table->index(['product_link_id', 'status']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE product_same_links
                ADD CONSTRAINT product_same_links_status_known CHECK (status IN ('active', 'unlinked')),
                -- Never itself, and stored once: the lower id is always side A.
                ADD CONSTRAINT product_same_links_ordered_pair CHECK (product_a_id < product_b_id),
                ADD CONSTRAINT product_same_links_unlink_is_recorded CHECK (
                    (status = 'unlinked') = (unlinked_by IS NOT NULL AND unlinked_at IS NOT NULL)
                );

            ALTER TABLE product_link_variant_mappings
                ADD CONSTRAINT product_link_variant_mappings_status_known CHECK (status IN ('active', 'removed')),
                ADD CONSTRAINT product_link_variant_mappings_removal_is_recorded CHECK (
                    (status = 'removed') = (removed_by IS NOT NULL AND removed_at IS NOT NULL)
                );

            CREATE UNIQUE INDEX product_same_links_one_active_pair
                ON product_same_links (product_a_id, product_b_id) WHERE status = 'active';

            CREATE UNIQUE INDEX product_link_variant_mappings_one_active_pair
                ON product_link_variant_mappings (product_link_id, COALESCE(variant_a_id, 0), COALESCE(variant_b_id, 0))
                WHERE status = 'active';

            CREATE TRIGGER product_same_links_locked_columns
                BEFORE UPDATE OF public_id, product_a_id, product_b_id, linked_by, linked_at, link_reason
                ON product_same_links
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'product_a_id', 'product_b_id', 'linked_by', 'linked_at', 'link_reason'
                );

            CREATE TRIGGER product_link_variant_mappings_locked_columns
                BEFORE UPDATE OF public_id, product_link_id, variant_a_id, variant_b_id, mapped_by, mapped_at, map_reason
                ON product_link_variant_mappings
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked(
                    'public_id', 'product_link_id', 'variant_a_id', 'variant_b_id', 'mapped_by', 'mapped_at', 'map_reason'
                );

            CREATE OR REPLACE FUNCTION feriwala_product_links_never_deleted() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'product links are never deleted: unlink or unmap them instead'
                    USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER product_same_links_never_deleted
                BEFORE DELETE ON product_same_links
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_links_never_deleted();

            CREATE TRIGGER product_link_variant_mappings_never_deleted
                BEFORE DELETE ON product_link_variant_mappings
                FOR EACH ROW EXECUTE FUNCTION feriwala_product_links_never_deleted();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS product_link_variant_mappings_never_deleted ON product_link_variant_mappings;
            DROP TRIGGER IF EXISTS product_same_links_never_deleted ON product_same_links;
            DROP FUNCTION IF EXISTS feriwala_product_links_never_deleted();
        SQL);

        Schema::dropIfExists('product_link_variant_mappings');
        Schema::dropIfExists('product_same_links');
    }
};
