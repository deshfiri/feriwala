<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A storefront sells only what its owner selected, at a price they set inside the
 * administrator's bounds. The whole-catalogue default is retired.
 *
 * Touches only `website_products` rows with `auto_listed = true` — the rows that
 * default created, which no owner ever chose or priced (an owner's price edit or
 * publish cleared the flag while the default was live):
 *
 *   - those still `published` become `unpublished`, with `unpublished_at` set and
 *     `sync_status = pending`, so the scheduled sync tells each storefront;
 *   - every one of them then has `auto_listed` cleared, so the rows are ordinary
 *     unpublished selections and a second run finds nothing to change.
 *
 * Prices, pricing rules, orders and stock are never read or written. The rows stay,
 * so an owner can still publish any of them deliberately.
 *
 * `websites.lists_whole_catalogue` and `website_products.auto_listed` are retained
 * for this deployment for migration/history compatibility. Nothing in the
 * application reads them; a later cleanup migration can drop them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('website_products')
            ->where('auto_listed', true)
            ->where('status', 'published')
            ->update([
                'status' => 'unpublished',
                'unpublished_at' => now(),
                'sync_status' => 'pending',
            ]);

        DB::table('website_products')
            ->where('auto_listed', true)
            ->update(['auto_listed' => false]);

        DB::table('websites')
            ->where('lists_whole_catalogue', true)
            ->update(['lists_whole_catalogue' => false]);

        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('lists_whole_catalogue')->default(false)->change();
        });
    }

    /**
     * Deliberately does not undo the cleanup: rolling back must never republish
     * the old catalogue, and the flags it cleared are not recoverable. The retired
     * listing code no longer exists, so restoring the old column default would
     * also do nothing.
     */
    public function down(): void {}
};
