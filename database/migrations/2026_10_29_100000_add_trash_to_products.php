<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reversible way to take a mistaken or test product out of circulation.
 *
 * `ManageProducts::delete()` used to allow removing only a history-less draft
 * outright — correct for a product nothing has ever referenced, but it left no
 * way to put away a product that was activated by mistake, offered by a
 * supplier, or stocked, short of leaving it live. Trash is that way: it hides
 * the product from every catalogue, cart, storefront and allocation lookup
 * immediately (Eloquent's own soft-delete scope), while leaving every table
 * that names it — status history, stock, supplier offers, sourcing, orders —
 * completely untouched. Nothing here drops or weakens any of those tables'
 * own "never deleted" protections.
 *
 * `deleted_by`/`deletion_reason` are who trashed it and why, shown on the
 * admin Trash screen. Restoring (or a later permanent delete, once the
 * product is confirmed to carry no real business history) clears both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deleted_by');

            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn(['deleted_at', 'deletion_reason']);
        });
    }
};
