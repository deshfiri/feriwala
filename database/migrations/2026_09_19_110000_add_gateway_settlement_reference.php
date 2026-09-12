<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's banking-side identifier for a settled payment (§26.3).
 *
 * Several providers carry two references: the one that identifies the
 * verification, and the one the banking side uses. SSLCommerz is the first —
 * its validation answers about `val_id` but its refund is addressed to
 * `bank_tran_id` — and it will not be the last.
 *
 * Kept when the provider confirms the payment rather than fetched again at
 * refund time, because a refund that first has to go and look up an identifier
 * has an extra way to fail at the moment somebody is owed their money.
 *
 * Not unique. It is evidence, not identity: `gateway_reference` is what one
 * provider transaction may claim exactly one payment by, and a second unique
 * index over a field only some providers populate would be a constraint that
 * means different things for different gateways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_settlement_reference')->nullable()->after('gateway_mode');

            $table->index('gateway_settlement_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_settlement_reference']);
            $table->dropColumn('gateway_settlement_reference');
        });
    }
};
