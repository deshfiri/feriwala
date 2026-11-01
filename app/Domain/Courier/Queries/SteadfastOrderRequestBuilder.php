<?php

namespace App\Domain\Courier\Queries;

use App\Domain\Courier\Exceptions\CourierBookingRefused;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Models\Order;
use App\Integrations\Courier\Steadfast\SteadfastOrderRequest;
use App\Support\Localization\MobileNumber;
use App\Support\Money\Currency;
use App\Support\Money\Money;

/**
 * The booking request for a Store Order, built **only** from what the order
 * froze at placement: its reference, the customer snapshot, the shipping
 * address snapshot and what it owes on delivery. Nothing is read from a live
 * customer or address record, so editing one later cannot change what a
 * booking says.
 *
 * Refuses, with a safe translated message, rather than send Steadfast something
 * it would reject or misread -- a booking is a chargeable action.
 */
class SteadfastOrderRequestBuilder
{
    public function __construct(
        protected MobileNumber $numbers,
    ) {}

    /**
     * @throws CourierBookingRefused
     */
    public function handle(Order $order): SteadfastOrderRequest
    {
        if ($order->source !== OrderSource::Website) {
            throw CourierBookingRefused::because('not_store_order');
        }

        if ($order->currency_code !== Currency::BDT->value) {
            throw CourierBookingRefused::because('unsupported_currency');
        }

        $customer = $order->customer;
        $address = $order->shipping_address ?? [];

        $name = $this->first([$customer['name'] ?? null, $customer['contact_name'] ?? null, $address['contact_name'] ?? null]);
        $mobile = $this->first([$customer['mobile'] ?? null, $address['contact_mobile'] ?? null]);

        if ($name === null) {
            throw CourierBookingRefused::because('missing_recipient');
        }

        $phone = $mobile === null ? null : $this->localNumber($mobile);

        if ($phone === null) {
            throw CourierBookingRefused::because('bad_phone');
        }

        $street = $this->first([$address['line1'] ?? null, $address['line_1'] ?? null]);

        if ($street === null) {
            throw CourierBookingRefused::because('missing_address');
        }

        return new SteadfastOrderRequest(
            invoice: $order->reference,
            recipientName: $name,
            recipientPhone: $phone,
            recipientAddress: $this->address($street, $address),
            codAmount: $order->isCashOnDelivery() ? $order->total : Money::zero(Currency::BDT),
        );
    }

    /**
     * Bangladeshi mobile as Steadfast's own example writes it: eleven digits,
     * `01XXXXXXXXX`. Null for anything that is not one.
     */
    protected function localNumber(string $mobile): ?string
    {
        $e164 = $this->numbers->normalise($mobile);

        if ($e164 === null || preg_match('/^\+8801[3-9][0-9]{8}$/', $e164) !== 1) {
            return null;
        }

        // "+880" is four characters; what follows is the ten digits after the leading 0.
        return '0'.substr($e164, 4);
    }

    /**
     * @param  array<string, string|null>  $address
     */
    protected function address(string $street, array $address): string
    {
        $parts = [
            $street,
            $this->first([$address['line2'] ?? null, $address['line_2'] ?? null]),
            $this->first([$address['area'] ?? null]),
            $this->first([$address['city'] ?? null]),
            $this->first([$address['district'] ?? null]),
            $this->first([$address['postcode'] ?? null]),
        ];

        return implode(', ', array_values(array_unique(array_filter($parts))));
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    protected function first(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
