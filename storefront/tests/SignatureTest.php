<?php

declare(strict_types=1);

use App\Domain\Website\Api\RequestSignature;
use Storefront\Erp\Signature;

suite('Signature');

test('builds the canonical request exactly as the contract describes', function () {
    $canonical = Signature::canonical('get', '/api/storefront/v1/products', 'limit=24&category=shirts', '1700000000', 'abc-nonce', '');

    same(
        "GET\n/api/storefront/v1/products?category=shirts&limit=24\n1700000000\nabc-nonce\n".hash('sha256', ''),
        $canonical,
    );
});

test('sorts query pairs byte-wise as sent, never decoding them', function () {
    $canonical = Signature::canonical('GET', '/p', 'b=2&a=%20x&a=+y', '1', 'n', '');

    contains("\n/p?a=%20x&a=+y&b=2\n", $canonical);
});

test('hashes the raw body, and an absent body as the empty string', function () {
    $body = '{"a":"é"}';

    contains(hash('sha256', $body), Signature::canonical('POST', '/p', '', '1', 'n', $body));
    contains(hash('sha256', ''), Signature::canonical('POST', '/p', '', '1', 'n', ''));
});

test('produces the same signature as the ERP’s own implementation', function () {
    // The monorepo carries the ERP's verifier: if this storefront and the ERP
    // ever disagree, this is the test that says so before a customer does.
    $erp = dirname(__DIR__, 2).'/app/Domain/Website/Api/RequestSignature.php';

    if (! is_file($erp)) {
        return;
    }

    require_once $erp;

    $cases = [
        ['GET', '/api/storefront/v1/products', 'limit=24&q=blue%20shirt&attributes%5Bsize%5D=M', '1790000000', '4f9c1c1e-0f3a-4a4a-9a55-1d2f3b4c5d6e', ''],
        ['POST', '/api/storefront/v1/orders', '', '1790000001', '7c1d2e3f-aaaa-4bbb-8ccc-0123456789ab', '{"storefront_order_reference":"SF-2026-000001","note":"বাংলা ✓"}'],
        ['PATCH', '/api/storefront/v1/customers/01X', 'z=1&a=2', '1790000002', 'nonce-3', '{}'],
    ];

    foreach ($cases as [$method, $path, $query, $timestamp, $nonce, $body]) {
        $theirs = RequestSignature::canonical($method, $path, $query, $timestamp, $nonce, $body);

        same($theirs, Signature::canonical($method, $path, $query, $timestamp, $nonce, $body), 'canonical request');
        same(
            RequestSignature::sign('s3cret', $theirs),
            Signature::sign('s3cret', Signature::canonical($method, $path, $query, $timestamp, $nonce, $body)),
            'signature',
        );
    }

    same(
        RequestSignature::authorization('wsk_K', 'abc'),
        Signature::authorization('wsk_K', 'abc'),
    );
});

test('nonces are version-4 uuids and do not repeat', function () {
    $a = Signature::nonce();
    $b = Signature::nonce();

    ok(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a) === 1, 'uuid v4 shape');
    ok($a !== $b);
});
