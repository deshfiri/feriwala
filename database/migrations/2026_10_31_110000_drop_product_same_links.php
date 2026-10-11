<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Same Product links are retired from active behaviour: no screen, route,
 * permission or allocation step uses them any more. One Central Product has one
 * BPC and many Supplier Offers.
 *
 * The tables they filled (`product_same_links`, `product_link_variant_mappings`)
 * are deliberately left in place as inactive legacy history. Nothing is dropped,
 * so any rows already written, and anything that referenced them, stay intact.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty: legacy history is kept, not destroyed.
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};
