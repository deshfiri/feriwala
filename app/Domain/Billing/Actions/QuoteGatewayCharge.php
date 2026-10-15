<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\ExchangeRates;
use App\Domain\Billing\Models\Payment;
use App\Integrations\Payment\Exceptions\GatewayUnavailable;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Money;

/**
 * Decides what a gateway is actually asked for, and keeps the answer (D4, amended).
 *
 * A Taka gateway is asked for the Taka amount, exactly as before. A gateway that
 * cannot take Taka is asked for the equivalent in a currency it accepts, at the
 * rate an administrator has set — and that figure and rate are stamped on the
 * payment before the gateway is called, because they are what the gateway's
 * confirmation will be checked against.
 *
 * **The Taka amount is never touched.** `amount` stays what the payer owes and
 * what every posting, invoice and refund limit is expressed in; the quote sits
 * beside it.
 *
 * **Quoted once per currency.** A payment that already holds a quote in the
 * currency being asked for keeps it, whatever the rate has become since. A payer
 * who retries is shown, and charged, the same figure, and an earlier attempt that
 * completes late still matches. Only a different charge currency re-quotes.
 */
class QuoteGatewayCharge
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected ExchangeRates $rates,
    ) {}

    /**
     * @return Money what the gateway should be asked to collect
     *
     * @throws GatewayUnavailable when the gateway cannot take this payment at all
     */
    public function handle(Payment $payment): Money
    {
        $gateway = (string) $payment->gateway;
        $currency = $payment->amount->currency;

        $charge = $this->gateways->chargeCurrencyFor($gateway, $currency);

        if ($charge === null) {
            throw GatewayUnavailable::forGateway(
                $gateway,
                "it cannot take {$currency->value} and no exchange rate is set for a currency it can take",
            );
        }

        // The gateway takes the payment as it stands. An earlier attempt through
        // another gateway may have left a quote behind; it no longer applies.
        if ($charge === $currency) {
            if ($payment->charge_amount !== null) {
                $payment->forceFill([
                    'charge_amount' => null,
                    'charge_currency_code' => null,
                    'fx_rate' => null,
                    'fx_quoted_at' => null,
                ])->save();
            }

            return $payment->amount;
        }

        if ($payment->charge_amount !== null && $payment->charge_amount->currency === $charge) {
            return $payment->charge_amount;
        }

        $rate = $this->rates->rateFor($charge);

        if ($rate === null) {
            throw GatewayUnavailable::forGateway($gateway, "no exchange rate is set for {$charge->value}");
        }

        $amount = $this->rates->chargeFor($payment->amount, $charge, $rate);

        // Cleared before it is set: the cast refuses to change a currency under
        // an existing amount, and a changed currency is exactly this case.
        $payment->forceFill(['charge_amount' => null, 'charge_currency_code' => null]);
        $payment->forceFill([
            'charge_amount' => $amount,
            'fx_rate' => $rate,
            'fx_quoted_at' => now(),
        ])->save();

        return $amount;
    }
}
