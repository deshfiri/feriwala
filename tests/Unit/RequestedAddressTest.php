<?php

use App\Domain\Website\RequestedAddress;

/*
 * The one cleaner every website-address field uses: whatever a person types or
 * pastes becomes the host they meant, so no field refuses an address another
 * field would take.
 */

it('reduces whatever was typed or pasted to the host', function (string $typed, string $host) {
    expect(RequestedAddress::normalize($typed))->toBe($host)
        ->and(RequestedAddress::host($typed))->toBe($host);
})->with([
    'plain' => ['shop.com', 'shop.com'],
    'capitals' => ['Shop.COM', 'shop.com'],
    'spaces around' => ['  shop.com  ', 'shop.com'],
    'non-breaking and zero-width spaces' => ["\u{00A0}shop.com\u{200B}", 'shop.com'],
    'a full URL' => ['https://www.Shop.com/about?x=1#top', 'www.shop.com'],
    'http' => ['http://shop.com', 'shop.com'],
    'trailing slash' => ['shop.com/', 'shop.com'],
    'a port' => ['shop.com:443', 'shop.com'],
    'a URL with a port' => ['https://shop.com:8443/cart', 'shop.com'],
    'a trailing dot' => ['shop.com.', 'shop.com'],
    'credentials' => ['https://user:pass@shop.com/', 'shop.com'],
    'a backslash' => ['shop.com\\path', 'shop.com'],
    'an underscore' => ['my_shop.example.com', 'my_shop.example.com'],
    'a Bangla domain' => ['দোকান.বাংলা', 'xn--p5b7ah1gxc.xn--54b7fta0cc'],
    'a .com.bd' => ['https://www.my-shop.com.bd/', 'www.my-shop.com.bd'],
]);

it('keeps a port typed for a development machine, and drops a default one', function (string $typed, string $address, string $origin) {
    expect(RequestedAddress::normalize($typed))->toBe($address)
        ->and(RequestedAddress::origin($typed))->toBe($origin);
})->with([
    '127.0.0.1 with a port' => ['http://127.0.0.1:8001', '127.0.0.1:8001', 'http://127.0.0.1:8001'],
    'localhost with a port' => ['http://localhost:8002', 'localhost:8002', 'http://localhost:8002'],
    '::1 with a port' => ['http://[::1]:8003/', '[::1]:8003', 'http://[::1]:8003'],
    'no scheme' => ['127.0.0.1:8001', '127.0.0.1:8001', 'http://127.0.0.1:8001'],
    'http on its default port' => ['http://127.0.0.1:80', '127.0.0.1', 'http://127.0.0.1'],
    'https on its default port' => ['https://example.com:443', 'example.com', 'https://example.com'],
    'a public host keeps no port' => ['https://shop.com:8443/cart', 'shop.com', 'https://shop.com'],
]);

it('refuses a bad port or a malformed address rather than guessing', function (string $typed) {
    expect(RequestedAddress::parts($typed))->toBeNull()
        ->and(RequestedAddress::normalize($typed))->toBe('')
        ->and(RequestedAddress::origin($typed))->toBeNull()
        ->and(RequestedAddress::host($typed))->toBeNull();
})->with([
    'port zero' => ['http://127.0.0.1:0'],
    'port too large' => ['http://localhost:65536'],
    'port not a number' => ['https://shop.com:abc'],
    'negative port' => ['shop.com:-1'],
    'a port with letters' => ['127.0.0.1:80a'],
    'only a scheme' => ['http://'],
    'no host before the path' => ['http:///shop'],
    'an illegal character' => ['http://shop$.com'],
    'not an IPv6 address' => ['http://[::zz]:80/'],
]);

it('says plainly when there is no domain in it at all', function (string $typed) {
    expect(RequestedAddress::host($typed))->toBeNull();
})->with([
    'empty' => [''],
    'no dot' => ['myshop'],
    'only a scheme' => ['https://'],
    'a leading hyphen' => ['-shop.com'],
    'an IP address' => ['http://127.0.0.1:8001'],
    'an IPv6 address' => ['http://[::1]:8000/'],
]);
