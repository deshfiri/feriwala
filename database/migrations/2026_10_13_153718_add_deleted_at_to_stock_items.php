<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets stock held for a deleted product be removed from Inventory.
 *
 * Movements, adjustments and reservations are append-only and point at the stock
 * row, so such a row cannot be hard-deleted. It is soft-deleted instead: it
 * leaves every list and count while its history stays exactly as written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
