<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which term a subscription carries on from (§8.2, §8.3).
 *
 * A renewal, an upgrade and a downgrade are each a **new** subscription record
 * rather than the old one edited — every term an account has held is history,
 * and rewriting a row in place overwrites what it cost, what it granted and
 * when it ran. What was missing was the link between them: without it, "which
 * term did this renew" has to be guessed from dates, and a same-day upgrade
 * makes that guess wrong.
 *
 * Nullable because a first purchase carries on from nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_packages', function (Blueprint $table) {
            $table->foreignId('renews_user_package_id')
                ->nullable()
                ->after('package_id')
                // Null rather than cascade: losing the earlier term must not
                // delete the one that replaced it, and the chain being broken
                // is better than the history being gone.
                ->constrained('user_packages')
                ->nullOnDelete();

            $table->index('renews_user_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renews_user_package_id');
        });
    }
};
