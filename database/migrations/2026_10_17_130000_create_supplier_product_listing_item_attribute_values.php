<?php

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured variant option groups for a Supplier's proposed variation
 * (Supplier Bulk Product Listing batch) -- Size, Colour, Material, Style and
 * so on, reusing the catalogue's own {@see ProductAttribute}/
 * {@see ProductAttributeValue} rather than a
 * parallel attribute system.
 *
 * Shaped exactly like the real `product_variant_values` pivot: a composite
 * primary key on `(supplier_product_listing_item_id, product_attribute_id)`
 * is what makes "one value per attribute per variant" a database fact, not
 * only an application check. A Supplier only ever *selects* an existing
 * attribute value here -- this table never grows a "created by supplier"
 * row of its own.
 *
 * Every constraint below is named explicitly rather than left to Laravel's
 * default `{table}_{column}_foreign` convention: this table's own name plus
 * `product_attribute_id` and `product_attribute_value_id` both exceed
 * Postgres's 63-byte identifier limit and, worse, truncate to the *same*
 * 63-byte prefix -- the two would collide as the identical constraint name
 * rather than merely being cut off.
 */
return new class extends Migration
{
    private const TABLE = 'supplier_product_listing_item_attribute_values';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_product_listing_item_id');
            $table->unsignedBigInteger('product_attribute_id');
            $table->unsignedBigInteger('product_attribute_value_id');

            $table->primary(
                ['supplier_product_listing_item_id', 'product_attribute_id'],
                'spliav_primary',
            );

            $table->foreign('supplier_product_listing_item_id', 'spliav_item_fk')
                ->references('id')->on('supplier_product_listing_items')->cascadeOnDelete();

            $table->foreign('product_attribute_id', 'spliav_attribute_fk')
                ->references('id')->on('product_attributes')->restrictOnDelete();

            $table->foreign('product_attribute_value_id', 'spliav_value_fk')
                ->references('id')->on('product_attribute_values')->restrictOnDelete();

            $table->index('product_attribute_value_id', 'spliav_value_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
