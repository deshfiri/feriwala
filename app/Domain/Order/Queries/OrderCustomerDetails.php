<?php

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;

/**
 * Who an order is for and where it goes, in one shape for every screen.
 *
 * Orders keep the customer and the address as they were when the order was
 * placed, but not all in the same words: a storefront or a Partner's manual
 * order keeps `name` / `mobile` and `line1` / `upazila` / `district`, a
 * wholesale order keeps `business_name` / `contact_name` and `line_1` / `area`.
 * Each screen used to read its own subset of those keys, and several read none
 * at all. This is the one reading of them, so the admin order, the store order
 * and the mobile API show the same customer.
 */
final class OrderCustomerDetails
{
    /**
     * @return array{name: string|null, phone: string|null, email: string|null, business_name: string|null, is_guest: bool|null, address: list<string>}
     */
    public static function of(Order $order): array
    {
        $customer = (array) $order->customer;
        $shipping = is_array($order->shipping_address) && $order->shipping_address !== []
            ? $order->shipping_address
            : (is_array($order->billing_address) ? $order->billing_address : []);

        return [
            'name' => self::first($customer, ['name', 'contact_name']) ?? self::first($shipping, ['contact_name', 'name']),
            'phone' => self::first($customer, ['mobile', 'phone']) ?? self::first($shipping, ['contact_mobile', 'mobile', 'phone']),
            'email' => self::first($customer, ['email']),
            'business_name' => self::first($customer, ['business_name']),
            'is_guest' => array_key_exists('is_guest', $customer) ? (bool) $customer['is_guest'] : null,
            'address' => self::addressLines($shipping),
        ];
    }

    /**
     * The address as the lines a person writes on a parcel: street, then the
     * locality, then district and division with the postcode, then the
     * country when it is not Bangladesh. Nothing repeated, nothing blank.
     *
     * @param  array<string, mixed>  $address
     * @return list<string>
     */
    public static function addressLines(array $address): array
    {
        $locality = self::join([
            self::first($address, ['union']),
            self::first($address, ['area']),
            self::first($address, ['upazila']),
            self::first($address, ['city']),
        ]);

        $region = self::join([self::first($address, ['district']), self::first($address, ['division'])]);
        $postcode = self::first($address, ['postcode']);

        if ($postcode !== null) {
            $region = $region === null ? $postcode : $region.' '.$postcode;
        }

        $country = self::first($address, ['country']);

        $lines = [
            self::first($address, ['line1', 'line_1', 'detailed_address']),
            self::first($address, ['line2', 'line_2', 'landmark']),
            $locality,
            $region,
            $country !== null && strtoupper($country) !== 'BD' ? $country : null,
        ];

        return array_values(array_filter($lines, fn (?string $line): bool => $line !== null));
    }

    /**
     * The first of `$keys` that holds a non-blank string.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private static function first(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * The parts joined with commas, each said once (a city and its upazila are
     * often the same name).
     *
     * @param  list<string|null>  $parts
     */
    private static function join(array $parts): ?string
    {
        $kept = [];

        foreach ($parts as $part) {
            if ($part !== null && ! in_array(mb_strtolower($part), array_map('mb_strtolower', $kept), true)) {
                $kept[] = $part;
            }
        }

        return $kept === [] ? null : implode(', ', $kept);
    }
}
