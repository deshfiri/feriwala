<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Non-Conditional account's storefront lists the whole eligible catalogue by
 * default; its owner may narrow it to chosen products.
 *
 * - `websites.lists_whole_catalogue`: the owner's choice. On by default, and
 *   only acted on for a Non-Conditional account.
 * - `website_products.auto_listed`: the row was put on sale by that default, not
 *   chosen by the owner. It is what a switch to "selected only" takes off, and
 *   what the package publish limit does not count. A price edit or a hide by the
 *   owner makes it the owner's own and clears the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('lists_whole_catalogue')->default(true);
        });

        Schema::table('website_products', function (Blueprint $table): void {
            $table->boolean('auto_listed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('website_products', function (Blueprint $table): void {
            $table->dropColumn('auto_listed');
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('lists_whole_catalogue');
        });
    }
};
