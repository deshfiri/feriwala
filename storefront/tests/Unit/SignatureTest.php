<?php

use App\Erp\Signature;

it('builds the canonical request exactly as the contract describes', function () {
    $canonical = Signature::canonical('get', '/api/storefront/v1/products', 'limit=24&category=shirts', '1700000000', 'abc-nonce', '');

    expect($canonical)->toBe("GET\n/api/storefront/v1/products?category=shirts&limit=24\n1700000000\nabc-nonce\n".hash('sha256', ''));
});

it('sorts query pairs byte-wise as sent, never decoding them', function () {
    $canonical = Signature::canonical('GET', '/p', 'b=2&a=%20x&a=+y', '1', 'n', '');

    expect($canonical)->toContain("\n/p?a=%20x&a=+y&b=2\n");
});

it('hashes the raw body, and an absent body as the empty string', function () {
    $body = '{"a":"é"}';

    expect(Signature::canonical('POST', '/p', '', '1', 'n', $body))->toContain(hash('sha256', $body))
        ->and(Signature::canonical('POST', '/p', '', '1', 'n', ''))->toContain(hash('sha256', ''));
});

it('signs the way the contract does: HMAC-SHA256 over a fixed canonical string', function () {
    // The reference vectors of the ERP's own verifier, worked by hand: if the
    // two ever disagree, this is the test that says so before a customer does.
    $cases = [
        ['GET', '/api/storefront/v1/products', 'limit=24&q=blue%20shirt&attributes%5Bsize%5D=M', '1790000000', '4f9c1c1e-0f3a-4a4a-9a55-1d2f3b4c5d6e', ''],
        ['POST', '/api/storefront/v1/orders', '', '1790000001', '7c1d2e3f-aaaa-4bbb-8ccc-0123456789ab', '{"storefront_order_reference":"SF-2026-000001","note":"বাংলা ✓"}'],
        ['PATCH', '/api/storefront/v1/customers/01X', 'z=1&a=2', '1790000002', 'nonce-3', '{}'],
    ];

    $expectedCanonical = [
        "GET\n/api/storefront/v1/products?attributes%5Bsize%5D=M&limit=24&q=blue%20shirt\n1790000000\n4f9c1c1e-0f3a-4a4a-9a55-1d2f3b4c5d6e\n".hash('sha256', ''),
        "POST\n/api/storefront/v1/orders\n1790000001\n7c1d2e3f-aaaa-4bbb-8ccc-0123456789ab\n".hash('sha256', '{"storefront_order_reference":"SF-2026-000001","note":"বাংলা ✓"}'),
        "PATCH\n/api/storefront/v1/customers/01X?a=2&z=1\n1790000002\nnonce-3\n".hash('sha256', '{}'),
    ];

    foreach ($cases as $index => [$method, $path, $query, $timestamp, $nonce, $body]) {
        $canonical = Signature::canonical($method, $path, $query, $timestamp, $nonce, $body);

        expect($canonical)->toBe($expectedCanonical[$index])
            ->and(Signature::sign('s3cret', $canonical))->toBe(hash_hmac('sha256', $expectedCanonical[$index], 's3cret'));
    }

    expect(Signature::authorization('wsk_K', 'abc'))->toBe('Feriwala-HMAC-SHA256 Credential=wsk_K, Signature=abc');
});

it('makes version-4 uuid nonces that do not repeat', function () {
    $a = Signature::nonce();
    $b = Signature::nonce();

    expect($a)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($a)->not->toBe($b);
});
