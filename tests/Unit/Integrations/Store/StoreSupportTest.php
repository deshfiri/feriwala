<?php

use App\Integrations\Store\Support\ExactJson;
use App\Integrations\Store\Support\OutboundAddressGuard;
use App\Integrations\Store\Support\StoreAddress;

/*
 * The small pieces the store integration trusts (D29): numbers that never
 * become floats, addresses reduced to one comparable form, and the line
 * between public and private networks.
 */

it('keeps every JSON number as the exact text it arrived as', function () {
    $decoded = ExactJson::decode('{"grand": 910.5, "qty": 2, "big": 12345678901234567890, "neg": -1.25, "exp": 1e3}');

    expect($decoded)->toBe(['grand' => '910.5', 'qty' => '2', 'big' => '12345678901234567890', 'neg' => '-1.25', 'exp' => '1e3']);
});

it('leaves digits inside strings, and escaped quotes, exactly as they were', function () {
    $decoded = ExactJson::decode('{"line": "House 12, Road \"5\" 10:30", "n": [1, 2.50]}');

    expect($decoded)->toBe(['line' => 'House 12, Road "5" 10:30', 'n' => ['1', '2.50']]);
});

it('reduces a pasted store address to its HTTPS origin and comparable host', function (string $input, string $base, string $host) {
    $address = StoreAddress::parse($input);

    expect($address?->baseUrl)->toBe($base)
        ->and($address?->normalizedHost)->toBe($host);
})->with([
    'with an API path' => ['https://Shop.Example.com/api/v1/', 'https://shop.example.com', 'shop.example.com'],
    'with the default port' => ['https://shop.example.com:443', 'https://shop.example.com', 'shop.example.com'],
    'with another port' => ['https://shop.example.com:8443/docs', 'https://shop.example.com:8443', 'shop.example.com:8443'],
]);

it('refuses an address that is not HTTPS or carries credentials', function (string $input) {
    expect(StoreAddress::parse($input))->toBeNull();
})->with(['http://shop.example.com', 'ftp://shop.example.com', 'https://user:pass@shop.example.com', 'shop.example.com']);

it('tells public addresses from private and reserved ones', function (string $address, bool $public) {
    expect(OutboundAddressGuard::isPublic($address))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['172.16.0.1', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['::1', false],
    ['fd00::1', false],
    ['::ffff:10.0.0.1', false],
]);
