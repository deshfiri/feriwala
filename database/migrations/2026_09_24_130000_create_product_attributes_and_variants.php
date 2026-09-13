<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attributes, their values, and product variations (§11.1).
 *
 * Attributes are shared across the catalogue — one "Size", one "Colour" — rather
 * than typed again on every product. Two products each carrying their own
 * "Colour" with its own "Navy" is how a storefront's colour filter ends up with
 * "Navy", "navy" and "Navy Blue" side by side.
 *
 * A variant is one combination of values on one product, with its own SKU. Four
 * rules are held by the database rather than left to the form:
 *
 *   - one value per attribute per variant — the pivot's primary key;
 *   - no two variants of a product with the same combination — a unique index on
 *     a canonical key built from the sorted value ids;
 *   - a variant SKU or barcode is never a product's, and the reverse — a trigger
 *     on both tables, because a unique index cannot span two tables and a
 *     warehouse scanning "FW-1043-M" must find exactly one thing;
 *   - SKUs upper-case and figures non-negative — CHECK constraints, as on
 *     products.
 *
 * Nothing here cascades from a product, an attribute or a value. A value in use
 * cannot be removed, and a variant is removed only with its product still a
 * draft — the pivot rows are part of the variant and go with it, which is the
 * one delete that is not a catalogue change in its own right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_attribute_id')->constrained('product_attributes')->restrictOnDelete();
            $table->string('value', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_attribute_id', 'sort_order']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();

            /*
             * The sorted attribute-value ids, joined. Written by the action, and
             * the thing the unique index below compares — so "Size M, Colour
             * Navy" and "Colour Navy, Size M" are the same combination however
             * they were chosen.
             */
            $table->string('combination_key', 255);

            $table->char('currency_code', 3)->default('BDT');

            // Null means the product's own figure applies. A variant that costs
            // the same as its product does not repeat the number, and so cannot
            // drift from it.
            $table->bigInteger('wholesale_price_minor')->nullable();
            $table->bigInteger('base_cost_minor')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'combination_key']);
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('product_variant_values', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('product_attribute_id')->constrained('product_attributes')->restrictOnDelete();
            $table->foreignId('product_attribute_value_id')->constrained('product_attribute_values')->restrictOnDelete();

            // One value per attribute per variant: a shirt is not both M and L.
            $table->primary(['product_variant_id', 'product_attribute_id']);
            $table->index('product_attribute_value_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX product_attributes_name_unique ON product_attributes (LOWER(name));
            CREATE UNIQUE INDEX product_attribute_values_value_unique
                ON product_attribute_values (product_attribute_id, LOWER(value));

            ALTER TABLE product_variants
                ADD CONSTRAINT product_variants_sku_upper CHECK (sku = UPPER(sku)),
                ADD CONSTRAINT product_variants_wholesale_price_not_negative
                    CHECK (wholesale_price_minor IS NULL OR wholesale_price_minor >= 0),
                ADD CONSTRAINT product_variants_base_cost_not_negative
                    CHECK (base_cost_minor IS NULL OR base_cost_minor >= 0);

            /*
             * One identifier namespace across products and variants — SKUs and
             * barcodes both. A scanner reading a code must find exactly one
             * thing, whichever table it lives in.
             *
             * The advisory locks are keyed on the identifier and held to the end
             * of the transaction, so two sessions writing the same code into
             * different tables at the same moment queue behind each other
             * instead of both finding the other table empty.
             */
            CREATE OR REPLACE FUNCTION feriwala_catalog_identifier_is_free() RETURNS trigger AS $$
            DECLARE
                other_table text := CASE WHEN TG_TABLE_NAME = 'products' THEN 'product_variants' ELSE 'products' END;
                taken boolean;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('catalog-sku:' || NEW.sku));

                EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE sku = $1)', other_table)
                    INTO taken USING NEW.sku;

                IF taken THEN
                    RAISE EXCEPTION 'SKU % is already used in %', NEW.sku, other_table
                        USING ERRCODE = 'unique_violation', CONSTRAINT = 'catalog_sku_namespace';
                END IF;

                IF NEW.barcode IS NOT NULL THEN
                    PERFORM pg_advisory_xact_lock(hashtext('catalog-barcode:' || NEW.barcode));

                    EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE barcode = $1)', other_table)
                        INTO taken USING NEW.barcode;

                    IF taken THEN
                        RAISE EXCEPTION 'Barcode % is already used in %', NEW.barcode, other_table
                            USING ERRCODE = 'unique_violation', CONSTRAINT = 'catalog_barcode_namespace';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER products_identifier_namespace
                BEFORE INSERT OR UPDATE OF sku, barcode ON products
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalog_identifier_is_free();

            CREATE TRIGGER product_variants_identifier_namespace
                BEFORE INSERT OR UPDATE OF sku, barcode ON product_variants
                FOR EACH ROW EXECUTE FUNCTION feriwala_catalog_identifier_is_free();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS products_identifier_namespace ON products;
            DROP TRIGGER IF EXISTS product_variants_identifier_namespace ON product_variants;
            DROP FUNCTION IF EXISTS feriwala_catalog_identifier_is_free();
        SQL);

        Schema::dropIfExists('product_variant_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_attribute_values');
        Schema::dropIfExists('product_attributes');
    }
};
