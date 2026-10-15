<?php

declare(strict_types=1);

use Storefront\Erp\Client;
use Storefront\Erp\ErpException;
use Storefront\Erp\Signature;
use Storefront\Support\Env;

suite('ERP client');

test('signs every request and verifies against the same secret', function () {
    $erp = (new FakeErp)->on('GET /products', fn () => [200, ['data' => [], 'meta' => ['has_more' => false]]]);

    $erp->client()->get('/products', ['limit' => 24, 'q' => 'blue shirt']);

    $call = $erp->seen[0];
    $auth = $call['headers']['authorization'];

    ok(preg_match('/^Feriwala-HMAC-SHA256 Credential=(wsk_[0-9A-Z]{26}), Signature=([0-9a-f]{64})$/', $auth, $m) === 1, 'authorization shape');

    $expected = Signature::sign('test-secret', Signature::canonical(
        'GET',
        $call['path'],
        $call['query'],
        $call['headers']['x-feriwala-timestamp'],
        $call['headers']['x-feriwala-nonce'],
        '',
    ));

    same($expected, $m[2]);
    ok(abs(time() - (int) $call['headers']['x-feriwala-timestamp']) < 5);
    contains('q=blue%20shirt', $call['query'], 'RFC 3986 encoding');
});

test('signs the exact body bytes it sends', function () {
    $erp = (new FakeErp)->on('POST /orders', fn () => [201, ['data' => ['id' => 'O1']]]);

    $erp->client()->post('/orders', ['name' => 'Nusrat — বাংলা', 'items' => [['sku' => 'A', 'quantity' => 1]]], 'key-1');

    $call = $erp->seen[0];
    preg_match('/Signature=([0-9a-f]{64})/', $call['headers']['authorization'], $m);

    same(
        Signature::sign('test-secret', Signature::canonical('POST', $call['path'], '', $call['headers']['x-feriwala-timestamp'], $call['headers']['x-feriwala-nonce'], (string) $call['raw'])),
        $m[1],
    );
    same('key-1', $call['headers']['idempotency-key']);
    same('application/json', $call['headers']['content-type']);
});

test('gives a write with no key one, so a retry can never double-submit', function () {
    $erp = (new FakeErp)->on('POST /customers', fn () => [201, ['data' => []]]);

    $erp->client()->post('/customers', ['name' => 'X']);

    ok(preg_match('/^[0-9a-f-]{36}$/', $erp->seen[0]['headers']['idempotency-key']) === 1);
});

test('retries a 503 with a fresh nonce and the same idempotency key, then succeeds', function () {
    $attempts = 0;
    $erp = (new FakeErp)->on('POST /orders', function () use (&$attempts) {
        $attempts++;

        return $attempts < 3
            ? [503, ['error' => ['code' => 'unavailable', 'message' => 'busy']]]
            : [201, ['data' => ['id' => 'O1']]];
    });

    $response = $erp->client()->post('/orders', ['a' => 1], 'idem-9');

    same('O1', $response['data']['id']);
    same(3, count($erp->seen));
    same(3, count(array_unique(array_column(array_column($erp->seen, 'headers'), 'x-feriwala-nonce'))), 'nonce is fresh each attempt');
    same(['idem-9'], array_values(array_unique(array_column(array_column($erp->seen, 'headers'), 'idempotency-key'))));
});

test('retries a 429 and surfaces Retry-After when it gives up', function () {
    $erp = (new FakeErp)->on('GET /products', fn () => [429, ['error' => ['code' => 'rate_limited', 'message' => 'slow']], ['Retry-After' => '0']]);

    $e = throws(ErpException::class, fn () => $erp->client()->get('/products'));

    same(429, $e->status);
    same(3, count($erp->seen));
    ok($e->isUnavailable());
});

test('never retries a 4xx other than 429', function () {
    $erp = (new FakeErp)->on('POST /orders', fn () => [422, ['error' => ['code' => 'insufficient_stock', 'message' => 'Only 3 remain', 'details' => ['available' => 3], 'request_id' => 'REQ1']]]);

    $e = throws(ErpException::class, fn () => $erp->client()->post('/orders', []), 'insufficient_stock');

    same(1, count($erp->seen));
    same(['available' => 3], $e->details);
    same('REQ1', $e->requestId);
    ok(! $e->isUnavailable());
});

test('tolerates unknown fields and treats an unreadable success as a fault', function () {
    $erp = (new FakeErp)->on('GET /site', fn () => [200, ['data' => ['name' => 'X', 'a_field_from_the_future' => [1, 2]]]]);

    same('X', $erp->client()->get('/site')['data']['name']);

    $client = new Client('https://erp.test', 'wsk_K', 's', null, 5, fn () => ['status' => 200, 'headers' => [], 'body' => '<html>nope']);

    throws(ErpException::class, fn () => $client->withoutBackoff()->get('/site'), 'malformed_response');
});

test('is connected by a store id and a token, and still reads the earlier names', function () {
    Env::set('APP_ENV', 'local');
    Env::set('ERP_BASE_URL', 'http://erp.test');
    Env::set('STORE_ID', 'wsk_NEWNAME');
    Env::set('STORE_TOKEN', 'new-token');
    Env::set('ERP_KEY_ID', 'wsk_OLDNAME');
    Env::set('ERP_SECRET', 'old-secret');

    same('wsk_NEWNAME', Client::storeId(), 'the new name wins');
    same('new-token', Client::storeToken());

    Env::set('STORE_ID', '');
    Env::set('STORE_TOKEN', '');

    same('wsk_OLDNAME', Client::storeId(), 'the earlier name still works');
    same('old-secret', Client::storeToken());

    Env::set('ERP_KEY_ID', '');
    Env::set('ERP_SECRET', '');

    throws(RuntimeException::class, fn () => Client::fromEnv());
});

test('refuses plain http outside local development', function () {
    Env::set('APP_ENV', 'production');
    Env::set('ERP_BASE_URL', 'http://erp.test');
    Env::set('STORE_ID', 'wsk_K');
    Env::set('STORE_TOKEN', 's');

    try {
        throws(RuntimeException::class, fn () => Client::fromEnv());
    } finally {
        Env::set('APP_ENV', 'local');
    }
});
