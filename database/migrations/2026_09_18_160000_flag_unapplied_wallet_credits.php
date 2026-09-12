<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that arrived and did not reach the wallet it was paid into (§24, §26.4).
 *
 * Settling a payment and crediting a wallet are two writes, and the second can
 * fail on its own: a currency that does not match, an account with no wallet, a
 * database that blinked. Rolling the settlement back is not an option — the
 * provider's evidence stands and the money is real — so the failure is recorded
 * *beside* the payment rather than by changing what the payment says about
 * itself.
 *
 * Deliberately **not** a payment status. `Paid` is true: the provider confirmed
 * it, the purchase was valid, and `ReconciliationRequired` already means
 * something else entirely — money confirmed after its checkout had closed. What
 * needs reconciling here is the credit, and that is what these columns say.
 *
 * The retry reads them, the administration screen lists them, and both clear
 * them by crediting — which is idempotent on the payment's own reference, so a
 * retry cannot pay somebody twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('wallet_credit_failed_at')->nullable();
            $table->text('wallet_credit_failure_reason')->nullable();

            // Cleared when the credit finally lands, so the column also answers
            // "did this ever reach the wallet".
            $table->timestamp('wallet_credited_at')->nullable();

            $table->index('wallet_credit_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['wallet_credit_failed_at']);
            $table->dropColumn([
                'wallet_credit_failed_at',
                'wallet_credit_failure_reason',
                'wallet_credited_at',
            ]);
        });
    }
};
