<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a payment was last checked against its provider (§28.1).
 *
 * The sweep needs somewhere to say "I asked about this one, and this is when".
 * Without it the only way to know is to read the payment log, which means the
 * cheapest question the sweep asks — what have I not looked at recently — costs
 * a scan of the most-written table in the system.
 *
 * `reconciliation_checked_at` is when we last asked, whatever the answer.
 * `reconciliation_matched_at` is when the provider last agreed with us. They
 * differ exactly when something is wrong, which is what makes the pair worth
 * having rather than one timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reconciliation_checked_at')->nullable()->after('reconciliation_required_at');
            $table->timestamp('reconciliation_matched_at')->nullable()->after('reconciliation_checked_at');

            /*
             * The sweep's own index: oldest-checked first, among payments that
             * are still open. Without it the query that finds work sorts the
             * whole table every time it runs.
             */
            $table->index(['status', 'reconciliation_checked_at'], 'payments_reconciliation_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_reconciliation_sweep_index');
            $table->dropColumn(['reconciliation_checked_at', 'reconciliation_matched_at']);
        });
    }
};
