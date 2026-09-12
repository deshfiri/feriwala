<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happens to a deposit afterwards (§24.4).
 *
 * §24.4 lists six configurable things, and they are not six values of one field.
 * Three answer "how much comes back" — fully, partly, not at all — and three are
 * separate conditions on the same money: held until the package is cancelled,
 * spendable on service charges, withdrawable once liabilities are settled. An
 * account can legitimately have a fully refundable deposit that is also reserved
 * until cancellation and also spendable, so folding them into one column would
 * make two of those unsayable.
 *
 * Captured onto the wallet alongside the figures, for the same reason those are:
 * a deposit taken under one set of terms is not governed by a different set
 * because somebody edited the policy afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposit_rules', function (Blueprint $table) {
            $table->string('refundability', 20)->default('full');

            // Only meaningful for a partial refund, and unsigned because a
            // negative percentage of somebody's deposit is not a thing.
            $table->unsignedTinyInteger('refundable_percent')->nullable();

            $table->boolean('reserved_until_cancellation')->default(false);
            $table->boolean('deposit_usable_for_charges')->default(true);
            $table->boolean('withdrawable_after_liabilities')->default(true);
        });

        Schema::table('wallet_deposit_obligations', function (Blueprint $table) {
            $table->string('refundability', 20)->default('full');
            $table->unsignedTinyInteger('refundable_percent')->nullable();
            $table->boolean('reserved_until_cancellation')->default(false);
            $table->boolean('withdrawable_after_liabilities')->default(true);
        });

        Schema::table('wallets', function (Blueprint $table) {
            /*
             * The wallet carries only what changes an arithmetic answer today.
             * The rest of §24.4 governs a refund and a withdrawal, and both of
             * those modules read the obligation they were taken under.
             */
            $table->boolean('deposit_reserved_until_cancellation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('deposit_reserved_until_cancellation');
        });

        Schema::table('wallet_deposit_obligations', function (Blueprint $table) {
            $table->dropColumn([
                'refundability',
                'refundable_percent',
                'reserved_until_cancellation',
                'withdrawable_after_liabilities',
            ]);
        });

        Schema::table('deposit_rules', function (Blueprint $table) {
            $table->dropColumn([
                'refundability',
                'refundable_percent',
                'reserved_until_cancellation',
                'deposit_usable_for_charges',
                'withdrawable_after_liabilities',
            ]);
        });
    }
};
