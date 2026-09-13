<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What partner websites put in a product page's head and structured data
 * (§11.1, §34.3).
 *
 * The metadata is optional and falls back to the product's own name and
 * description, so a product is never published with an empty title. The schema
 * fields are the ones only the catalogue can know: the manufacturer part number,
 * the condition it is sold in, and which of its images represents it when shared.
 * The rest of a Product schema — name, SKU, GTIN (the barcode), brand, images —
 * is read from fields the product already has, rather than typed twice.
 *
 * The social image points at one of the product's own media, nulled if that
 * file is removed. It is never a URL typed in, because a URL typed in can point
 * anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_title', 70)->nullable()->after('description');
            $table->string('meta_description', 200)->nullable()->after('meta_title');
            $table->string('meta_keywords', 255)->nullable()->after('meta_description');

            $table->string('mpn', 70)->nullable()->after('barcode');
            $table->string('item_condition', 20)->default('new')->after('mpn');

            $table->foreignId('social_media_id')->nullable()->after('item_condition')
                ->constrained('product_media')->nullOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_item_condition_known
                    CHECK (item_condition IN ('new', 'refurbished', 'used'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_item_condition_known');

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('social_media_id');
            $table->dropColumn(['meta_title', 'meta_description', 'meta_keywords', 'mpn', 'item_condition']);
        });
    }
};
