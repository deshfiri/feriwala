<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a gateway that issues a per-attempt id needs kept against the payment.
 *
 * `payer_address_snapshot` freezes the postal address an attempt was sent with
 * (EPS requires one), so a later edit to the account's address never rewrites
 * what was submitted. `gateway_session_reference` is the provider's own id for
 * the hosted payment session — evidence for a support conversation, never an
 * identity anything is matched or settled by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->json('payer_address_snapshot')->nullable()->after('gateway_mode');
            $table->string('gateway_session_reference')->nullable()->after('payer_address_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payer_address_snapshot', 'gateway_session_reference']);
        });
    }
};
