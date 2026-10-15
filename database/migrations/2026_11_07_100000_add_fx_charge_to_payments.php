<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an international gateway was actually asked to take (D4, amended).
 *
 * The base ledger stays in Taka: `amount` and `currency_code` are what the payer
 * owes and what every downstream posting uses, and they are never rewritten. A
 * gateway that cannot take Taka (Stripe, PayPal) is asked for the equivalent in a
 * currency it can, and that request is kept here, beside the charge rather than
 * instead of it:
 *
 *   - `charge_amount` / `charge_currency_code` — what the gateway was asked for,
 *     and therefore what it must report back for the payment to settle.
 *   - `fx_rate` — Taka per one unit of the charge currency, as quoted.
 *   - `fx_quoted_at` — when the rate was fixed for this payment.
 *
 * All nullable: a payment taken in Taka through a Taka gateway has no conversion
 * and carries none. The rate is stamped once and kept for the life of the payment,
 * so a refund is converted at the rate the money was taken at, not today's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('charge_currency_code', 3)->nullable()->after('currency_code');
            $table->decimal('charge_amount', 19, 2)->nullable()->after('charge_currency_code');
            $table->decimal('fx_rate', 19, 8)->nullable()->after('charge_amount');
            $table->timestamp('fx_quoted_at')->nullable()->after('fx_rate');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['charge_currency_code', 'charge_amount', 'fx_rate', 'fx_quoted_at']);
        });
    }
};
