<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Models\Order;
use App\Integrations\Payment\Data\PayerAddress;

/**
 * What a gateway that identifies each attempt itself needs saved *before* it
 * is called (EPS).
 *
 * Writes the new attempt's identifier to `gateway_reference` — the column every
 * return and notification is matched against — and freezes the payer address
 * the attempt will be sent with. Both are on the payment before any request
 * leaves, so a browser that returns before the response is processed still
 * finds them. Other gateways are untouched.
 */
class PreparePaymentAttempt
{
    /** Gateways that issue the attempt identifier themselves rather than reuse our reference. */
    protected const GATEWAYS = ['eps'];

    public function __construct(
        protected ResolvePayerAddress $address,
    ) {}

    /**
     * @return array{reference: string|null, address: PayerAddress|null, missing: list<string>}
     */
    public function handle(Payment $payment): array
    {
        if (! in_array($payment->gateway, self::GATEWAYS, true)) {
            return ['reference' => null, 'address' => null, 'missing' => []];
        }

        // An order's payment is addressed with what the order was placed with.
        $order = Order::query()->where('payment_id', $payment->id)->first();

        $address = $this->address->handle($payment->businessAccount, $order);
        $reference = $this->newReference();

        $payment->forceFill([
            'gateway_reference' => $reference,
            'payer_address_snapshot' => $address === null ? null : [
                'line' => $address->line,
                'city' => $address->city,
                'state' => $address->state,
                'postcode' => $address->postcode,
                'country' => $address->country,
            ],
        ])->save();

        return [
            'reference' => $reference,
            'address' => $address,
            'missing' => $address === null ? $this->address->missing($payment->businessAccount, $order) : [],
        ];
    }

    /**
     * Sixteen digits: a second-resolution timestamp and four random digits,
     * re-drawn if it has ever been used. EPS asks for a unique numeric id of at
     * least ten digits per transaction.
     */
    protected function newReference(): string
    {
        do {
            $candidate = now()->format('ymdHis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (Payment::query()->where('gateway_reference', $candidate)->exists());

        return $candidate;
    }
}
