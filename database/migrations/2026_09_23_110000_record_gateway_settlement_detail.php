<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the provider actually kept and settled (D4, §26.4).
 *
 * `amount_minor` and `currency_code` say what was charged. `settled_amount_minor`
 * and `settled_currency_code` already said what the provider reported settling.
 * What was missing is the third figure: what they took for themselves.
 *
 * A gateway charge that is billed to the customer is already a
 * `payment_allocations` row — it is part of what was charged, and the invoice
 * shows it. This is the other thing entirely: the fee the provider deducted at
 * their end, which never appeared on any invoice and is the difference between
 * what the customer paid and what arrived. SSLCommerz calls it `store_amount`,
 * Stripe an application fee, bKash a commission.
 *
 * Recorded separately and never netted off. A figure that mixed the charge and
 * the fee could not answer either question: what the customer paid, or what the
 * business received.
 *
 * **No exchange rate is stored, and none is calculated.** D4 is explicit that
 * version 1 performs no exchange-rate accounting, and the international drivers
 * decline any currency they would have to convert rather than inventing a rate.
 * Storing a rate column here would be an invitation to start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            /*
             * What the provider deducted, in the currency they settled in.
             * Nullable because most providers do not report it and inventing a
             * zero would claim they charged nothing.
             */
            $table->bigInteger('gateway_fee_minor')->nullable()->after('settled_amount_minor');
            $table->string('gateway_fee_currency_code', 3)->nullable()->after('gateway_fee_minor');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['gateway_fee_minor', 'gateway_fee_currency_code']);
        });
    }
};
