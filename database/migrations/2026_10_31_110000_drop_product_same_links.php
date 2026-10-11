<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same Product links are retired: which Suppliers actually have a Product is
 * now recorded by Supplier assignments, and order sources come from the ordered
 * Product's own offers and stock.
 *
 * Drops the link and variation-match tables and their guard functions. Nothing
 * else referenced them by foreign key, and orders keep the source Product they
 * were allocated from as a snapshot on the allocation itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_link_variant_mappings');
        Schema::dropIfExists('product_same_links');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS feriwala_product_links_never_deleted() CASCADE;
        SQL);
    }

    public function down(): void
    {
        // Retired for good: the earlier migration that created these tables
        // still describes their shape if one ever has to be rebuilt.
    }
};
