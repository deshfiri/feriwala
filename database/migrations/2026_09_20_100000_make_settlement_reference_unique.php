<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One provider settlement, one payment (§26.4, §36.1).
 *
 * `gateway_reference` is already unique, but it is not always the identifier
 * that means "this money". Several providers carry two: the one a verification
 * is addressed to, and the one the banking side settled under. SSLCommerz can
 * issue a fresh `val_id` against the same `bank_tran_id`, so two payments could
 * hold distinct gateway references and the same real transaction — which is one
 * payment of real money settling two orders, the exact failure the other index
 * exists to make impossible.
 *
 * The application checks this too, and that check gives a readable answer
 * instead of a 500. This is what holds under two concurrent callbacks, which no
 * check-then-write can.
 *
 * Nulls do not collide in PostgreSQL, so the providers that carry only one
 * identifier are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['gateway_settlement_reference']);
            $table->unique('gateway_settlement_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['gateway_settlement_reference']);
            $table->index('gateway_settlement_reference');
        });
    }
};
