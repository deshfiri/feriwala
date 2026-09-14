<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouses and the stock each one holds (§19, P3-22).
 *
 * Central stock is Feriwala's and lives here, per warehouse and per stockable
 * unit: a product that has no variations is stocked as itself, a product with
 * variations is stocked per variation, because a warehouse holds "12 of M,
 * Navy", never "12 of the panjabi".
 *
 * A stock item holds six buckets, one per state §19 names. A unit is always in
 * exactly one of them, so a movement from one to another is the only way a
 * figure changes (P3-23), and none can go below zero — held by CHECK, because
 * overselling is a race the application loses without the database (§19, §36.1).
 *
 * What a row is about never changes once written: moving stock between
 * warehouses or SKUs is a movement out of one item and into another, not a
 * rewrite of which item this was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Short, printable, and the same on a label as on the screen.
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->text('address')->nullable();

            $table->boolean('is_active')->default(true);

            // D15: the assigned default warehouse is tried first, then the rest
            // in priority order.
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('priority')->default(0);

            $table->timestamps();
        });

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();

            $table->integer('available')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('processing')->default(0);
            $table->integer('sold')->default(0);
            $table->integer('returned')->default(0);
            $table->integer('damaged')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'product_variant_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE warehouses
                ADD CONSTRAINT warehouses_code_format CHECK (code ~ '^[A-Z0-9][A-Z0-9-]*$'),
                -- A switched-off warehouse cannot be the one stock is looked for first.
                ADD CONSTRAINT warehouses_default_is_active CHECK (NOT is_default OR is_active);

            CREATE UNIQUE INDEX warehouses_one_default ON warehouses (is_default) WHERE is_default;

            -- Postgres treats nulls as distinct, so one index per scope.
            CREATE UNIQUE INDEX stock_items_product_unique
                ON stock_items (warehouse_id, product_id)
                WHERE product_variant_id IS NULL;

            CREATE UNIQUE INDEX stock_items_variant_unique
                ON stock_items (warehouse_id, product_variant_id)
                WHERE product_variant_id IS NOT NULL;

            ALTER TABLE stock_items
                ADD CONSTRAINT stock_items_buckets_not_negative CHECK (
                    available >= 0 AND reserved >= 0 AND processing >= 0
                    AND sold >= 0 AND returned >= 0 AND damaged >= 0
                );

            CREATE OR REPLACE FUNCTION feriwala_stock_item_variant_matches_product() RETURNS trigger AS $$
            BEGIN
                IF NEW.product_variant_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM product_variants
                    WHERE id = NEW.product_variant_id AND product_id = NEW.product_id
                ) THEN
                    RAISE EXCEPTION 'stock_items: variation % is not a variation of product %', NEW.product_variant_id, NEW.product_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER stock_items_variant_matches_product
                BEFORE INSERT ON stock_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_stock_item_variant_matches_product();

            CREATE TRIGGER warehouses_locked_columns
                BEFORE UPDATE OF public_id, code ON warehouses
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'code');

            CREATE TRIGGER stock_items_locked_columns
                BEFORE UPDATE OF public_id, warehouse_id, product_id, product_variant_id ON stock_items
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalogue_columns_are_locked('public_id', 'warehouse_id', 'product_id', 'product_variant_id');
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('warehouses');

        DB::unprepared('DROP FUNCTION IF EXISTS feriwala_stock_item_variant_matches_product();');
    }
};
