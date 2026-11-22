<?php

use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\OrderCustomerDetails;

/*
 * The one reading of who an order is for and where it goes, whichever kind of
 * order wrote the snapshot.
 */

it('reads a storefront or manual order: name, mobile, and the address down to the union', function () {
    $order = new Order([
        'customer' => ['name' => 'Ayesha Rahman', 'mobile' => '+8801712345678', 'email' => 'a@example.test', 'is_guest' => true],
        'shipping_address' => [
            'line1' => 'House 12, Road 5', 'line2' => null, 'city' => 'Dhanmondi', 'upazila' => 'Dhanmondi',
            'union' => null, 'district' => 'Dhaka', 'division' => 'Dhaka', 'postcode' => '1209', 'country' => 'BD',
        ],
    ]);

    expect(OrderCustomerDetails::of($order))->toBe([
        'name' => 'Ayesha Rahman',
        'phone' => '+8801712345678',
        'email' => 'a@example.test',
        'business_name' => null,
        'is_guest' => true,
        // Dhanmondi once, Dhaka once, Bangladesh left unsaid.
        'address' => ['House 12, Road 5', 'Dhanmondi', 'Dhaka 1209'],
    ]);
});

it('reads a wholesale order: the business, its contact, and the saved address', function () {
    $order = new Order([
        'customer' => ['business_name' => 'Karim Traders', 'contact_name' => 'Karim', 'email' => 'k@example.test', 'mobile' => '+8801811111111'],
        'shipping_address' => [
            'contact_name' => 'Rahim', 'contact_mobile' => '+8801922222222', 'line_1' => 'Shop 4, New Market',
            'line_2' => 'Second floor', 'area' => 'Azimpur', 'city' => 'Dhaka', 'district' => 'Dhaka', 'postcode' => null, 'country' => 'BD',
        ],
    ]);

    expect(OrderCustomerDetails::of($order))->toMatchArray([
        'name' => 'Karim',
        'phone' => '+8801811111111',
        'business_name' => 'Karim Traders',
        'is_guest' => null,
        'address' => ['Shop 4, New Market', 'Second floor', 'Azimpur, Dhaka', 'Dhaka'],
    ]);
});

it('falls back to the address contact and the billing address, and names a foreign country', function () {
    $order = new Order([
        'customer' => [],
        'shipping_address' => [],
        'billing_address' => ['contact_name' => 'Rahim', 'contact_mobile' => '+8801922222222', 'line_1' => '1 High St', 'city' => 'London', 'country' => 'GB'],
    ]);

    expect(OrderCustomerDetails::of($order))->toMatchArray([
        'name' => 'Rahim',
        'phone' => '+8801922222222',
        'address' => ['1 High St', 'London', 'GB'],
    ]);
});

it('says nothing rather than something wrong when the order recorded nothing', function () {
    expect(OrderCustomerDetails::of(new Order))->toBe([
        'name' => null, 'phone' => null, 'email' => null, 'business_name' => null, 'is_guest' => null, 'address' => [],
    ]);
});
