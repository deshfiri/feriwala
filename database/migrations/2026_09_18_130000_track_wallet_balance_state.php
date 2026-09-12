<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this wallet fell short, and how long it has (§24.1, §24.3).
 *
 * §24.1 configures a grace period; §24.3 makes things happen when it runs out.
 * Between them sits a fact nobody else records: the moment the balance first
 * dropped below what the account is required to hold.
 *
 * It has to be **stamped**, not computed. A grace period is a promise — "you
 * have fourteen days" — and a promise recalculated on every sweep is a deadline
 * that moves whenever an administrator edits a setting, which is exactly the
 * thing somebody would complain about afterwards. So the deadline is written
 * once, when the shortfall begins, and left alone until the obligation is met
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // When the balance first fell below the obligation. Null means it
            // has not — which is different from a shortfall that began today.
            $table->timestamp('shortfall_since')->nullable();

            // Stamped from the grace period in force when the shortfall began,
            // and never moved while it lasts.
            $table->timestamp('grace_ends_at')->nullable();

            // The state the last evaluation left it in, so a screen and a list
            // can read it without re-deriving it per row.
            $table->string('balance_state', 20)->default('healthy');
            $table->timestamp('balance_checked_at')->nullable();

            $table->index(['balance_state', 'grace_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex(['balance_state', 'grace_ends_at']);
            $table->dropColumn([
                'shortfall_since',
                'grace_ends_at',
                'balance_state',
                'balance_checked_at',
            ]);
        });
    }
};
