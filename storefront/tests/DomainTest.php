<?php

declare(strict_types=1);

use Storefront\App;
use Storefront\Domain\Cart;
use Storefront\Domain\Checkout;
use Storefront\Domain\Orders;
use Storefront\Domain\Webhooks;
use Storefront\Erp\ErpException;
use Storefront\Support\ApiCache;
use Storefront\Support\Db;
use Storefront\Support\Env;
use Storefront\Support\Throttle;

suite('Cart');

test('keeps SKUs and quantities, never prices', function () {
    freshDatabase();
    $cart = Cart::forToken(null);

    $cart->add('fw-1-m', 2, ['product_id' => '01P', 'slug' => 'panjabi', 'name' => 'Panjabi', 'variant' => 'M / Navy', 'image' => null]);
    $cart->add('FW-1-M', 3, ['product_id' => '01P', 'slug' => 'panjabi', 'name' => 'Panjabi', 'variant' => 'M / Navy', 'image' => null]);

    $items = Cart::forToken($cart->token())->items();

    same(1, count($items));
    same('FW-1-M', $items[0]['sku'], 'SKUs are normalised the way the ERP reads them');
    same(5, $items[0]['quantity']);
    same(5, Cart::forToken($cart->token())->count());

    $columns = array_column(Db::all('SHOW COLUMNS FROM cart_items'), 'Field');
    ok(! in_array('price', $columns, true) && ! in_array('unit_price', $columns, true), 'no price column');
});

test('sets, removes and clears', function () {
    freshDatabase();
    $cart = Cart::forToken(null);
    $meta = ['product_id' => '01P', 'slug' => 's', 'name' => 'N', 'variant' => null, 'image' => null];

    $cart->add('A', 1, $meta);
    $cart->add('B', 1, $meta);
    $cart->setQuantity('A', 7);
    same(8, $cart->count());

    $cart->setQuantity('A', 0);
    same(['B'], array_column($cart->items(), 'sku'));

    $cart->remove('B');
    ok($cart->isEmpty());
});

test('refuses a cart that grows without bound', function () {
    freshDatabase();
    $cart = Cart::forToken(null);
    $meta = ['product_id' => '01P', 'slug' => 's', 'name' => 'N', 'variant' => null, 'image' => null];

    for ($i = 0; $i < Cart::MAX_LINES; $i++) {
        $cart->add('SKU-'.$i, 1, $meta);
    }

    throws(DomainException::class, fn () => $cart->add('ONE-TOO-MANY', 1, $meta));
});

test('ignores a malformed cookie token', function () {
    freshDatabase();

    same([], Cart::forToken("'; DROP TABLE carts;--")->items());
    same(null, Cart::forToken('short')->token());
});

suite('Checkout');

/**
 * A cart with one line, and a fake ERP that quotes and accepts it.
 *
 * @return array{0: FakeErp, 1: array<string, mixed>}
 */
function checkoutFixture(string $method = 'online'): array
{
    freshDatabase();

    $erp = (new FakeErp)
        ->on('POST /checkout/quote', fn () => [200, ['data' => [
            'currency' => 'BDT',
            'items' => [[
                'sku' => 'FW-1-M', 'quantity' => 2,
                'unit_price' => moneyBody('2490.00'), 'line_total' => moneyBody('4980.00'),
                'availability' => ['in_stock' => true, 'quantity' => 12, 'reservable' => true],
            ]],
            'subtotal' => moneyBody('4980.00'), 'discount' => moneyBody('0.00'), 'shipping' => moneyBody('60.00'),
            'tax' => moneyBody('0.00'), 'tax_included' => moneyBody('0.00'), 'grand_total' => moneyBody('5040.00'),
            'payment' => ['methods' => ['online', 'cod'], 'cod' => ['enabled' => true, 'eligible' => true, 'maximum' => null]],
            'quoted_at' => gmdate('c'), 'valid_until' => gmdate('c', time() + 600),
        ]]]);

    App::useErp($erp->client());

    $cart = Cart::forToken(null);
    $cart->add('FW-1-M', 2, ['product_id' => '01P', 'slug' => 'cotton-panjabi', 'name' => 'Cotton Panjabi', 'variant' => 'M / Navy', 'image' => null]);

    $checked = Checkout::validate([
        'name' => 'Nusrat Jahan', 'phone' => '01712-345678', 'email' => '', 'line1' => 'House 1, Road 2',
        'city' => 'Dhaka', 'district' => 'Dhaka', 'payment_method' => $method,
    ], ['online', 'cod']);

    same([], $checked['errors']);

    $checkout = Checkout::create($cart, $checked['data']);
    Checkout::quote($checkout);

    return [$erp, (array) Checkout::find((string) $checkout['token'])];
}

test('validates what a customer typed, in words per field', function () {
    $bad = Checkout::validate(['name' => 'A', 'phone' => 'abc', 'email' => 'nope', 'line1' => '', 'city' => '', 'payment_method' => 'bitcoin'], ['online', 'cod']);

    same(['name', 'phone', 'email', 'line1', 'city', 'payment_method'], array_keys($bad['errors']));
});

test('an online payment must name a gateway the ERP offered', function () {
    $base = ['name' => 'Nusrat Jahan', 'phone' => '01712345678', 'line1' => 'House 1', 'city' => 'Dhaka'];

    $none = Checkout::validate($base + ['payment_method' => 'online', 'gateway' => ''], ['online', 'cod'], ['eps', 'bkash']);
    same(['payment_method'], array_keys($none['errors']));

    $unknown = Checkout::validate($base + ['payment_method' => 'online', 'gateway' => 'bitcoin'], ['online', 'cod'], ['eps', 'bkash']);
    same(['payment_method'], array_keys($unknown['errors']));

    $chosen = Checkout::validate($base + ['payment_method' => 'online', 'gateway' => 'eps'], ['online', 'cod'], ['eps', 'bkash']);
    same([], $chosen['errors']);
    same('eps', $chosen['data']['gateway']);

    $lone = Checkout::validate($base + ['payment_method' => 'online'], ['online'], ['eps']);
    same('eps', $lone['data']['gateway']);

    $cod = Checkout::validate($base + ['payment_method' => 'cod', 'gateway' => 'eps'], ['online', 'cod'], ['eps', 'bkash']);
    same('', $cod['data']['gateway']);
});

test('numbers the order and stores a draft with the cart’s lines', function () {
    [, $checkout] = checkoutFixture();

    ok(preg_match('/^SF-\d{4}-\d{6}-[0-9A-F]{4}$/', (string) $checkout['reference']) === 1, 'reference '.$checkout['reference']);
    same('draft', $checkout['status']);
    same('FW-1-M', $checkout['items_json'][0]['sku']);
    same('5040.00', $checkout['quote_json']['grand_total']['amount']);
});

test('submits exactly the figures the ERP quoted, in flat Taka and never minor units', function () {
    [$erp, $checkout] = checkoutFixture();

    $erp->on('POST /orders', fn ($body) => [201, ['data' => ['id' => '01ORDER', 'reference' => 'ORD-1', 'status' => 'payment_pending', 'payment' => ['method' => 'online', 'redirect_url' => 'https://pay.test/s/1'], 'totals' => ['grand_total' => moneyBody('5040.00')], 'placed_at' => gmdate('c')]]]);

    $result = Checkout::place($checkout, 'https://shop.test/payment/return');

    same('placed', $result['outcome']);
    same('01ORDER', $result['order']['id']);

    $body = $erp->calls('POST', '/orders')[0]['body'];

    same($checkout['reference'], $body['storefront_order_reference']);
    same(['amount' => '2490.00', 'currency' => 'BDT'], $body['items'][0]['unit_price']);
    same(['amount' => '5040.00', 'currency' => 'BDT'], $body['totals']['grand_total']);
    same('https://shop.test/payment/return', $body['payment']['return_url']);
    same('online', $body['payment']['method']);
    ok(! str_contains((string) $erp->calls('POST', '/orders')[0]['raw'], 'minor_units'), 'no legacy minor_units');
    same($checkout['idempotency_key'], $erp->calls('POST', '/orders')[0]['headers']['idempotency-key']);
});

test('on a price change shows the new total and needs a new key to be confirmed', function () {
    [$erp, $checkout] = checkoutFixture();

    $erp->on('POST /orders', fn () => [422, ['error' => ['code' => 'price_mismatch', 'message' => 'changed', 'details' => [
        'authoritative' => [
            'items' => [['sku' => 'FW-1-M', 'quantity' => 2, 'unit_price' => moneyBody('2590.00'), 'line_total' => moneyBody('5180.00')]],
            'subtotal' => moneyBody('5180.00'), 'discount' => moneyBody('0.00'), 'tax' => moneyBody('0.00'),
            'shipping' => moneyBody('60.00'), 'grand_total' => moneyBody('5240.00'),
        ],
        'submitted_grand_total' => moneyBody('5040.00'),
    ]]]]);

    $result = Checkout::place($checkout, null);

    same('repriced', $result['outcome']);
    same('5240.00', $result['quote']['grand_total']['amount']);
    ok($result['quote']['repriced']);

    $after = (array) Checkout::find((string) $checkout['token']);

    same('draft', $after['status'], 'back to a draft: nothing was placed');
    ok($after['idempotency_key'] !== $checkout['idempotency_key'], 'a new idempotency key');
    same('5240.00', $after['quote_json']['grand_total']['amount']);
    same('SF-', substr((string) $after['reference'], 0, 3));
    same($checkout['reference'], $after['reference'], 'the same storefront reference');

    // Confirming the new total submits the new figures.
    $erp->on('POST /orders', fn () => [201, ['data' => ['id' => '01ORDER2', 'status' => 'payment_pending', 'payment' => ['method' => 'online'], 'totals' => ['grand_total' => moneyBody('5240.00')]]]]);

    $second = Checkout::place($after, null);

    same('placed', $second['outcome']);
    same('5240.00', $erp->calls('POST', '/orders')[1]['body']['totals']['grand_total']['amount']);
    ok($erp->calls('POST', '/orders')[1]['headers']['idempotency-key'] !== $erp->calls('POST', '/orders')[0]['headers']['idempotency-key']);
});

test('a refusal puts the checkout back to a draft under the same key', function () {
    [$erp, $checkout] = checkoutFixture();

    $erp->on('POST /orders', fn () => [422, ['error' => ['code' => 'insufficient_stock', 'message' => 'Only 1 left', 'details' => ['available' => 1]]]]);

    throws(ErpException::class, fn () => Checkout::place($checkout, null), 'insufficient_stock');

    $after = (array) Checkout::find((string) $checkout['token']);

    same('draft', $after['status']);
    same($checkout['idempotency_key'], $after['idempotency_key']);
});

test('an outage is retried under the same key so an order is never taken twice', function () {
    [$erp, $checkout] = checkoutFixture();

    $erp->on('POST /orders', fn () => [503, ['error' => ['code' => 'unavailable', 'message' => 'down']]]);

    $e = throws(ErpException::class, fn () => Checkout::place($checkout, null));
    ok($e->isUnavailable());

    $after = (array) Checkout::find((string) $checkout['token']);
    same('draft', $after['status']);
    same($checkout['idempotency_key'], $after['idempotency_key']);
});

test('does not place the same checkout twice at once', function () {
    [$erp, $checkout] = checkoutFixture();

    Db::exec("UPDATE checkouts SET status = 'placing', updated_at = ? WHERE id = ?", [Db::now(), $checkout['id']]);

    throws(DomainException::class, fn () => Checkout::place($checkout, null));

    // …but a claim older than two minutes is taken as abandoned.
    Db::exec('UPDATE checkouts SET updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 300), $checkout['id']]);
    $erp->on('POST /orders', fn () => [201, ['data' => ['id' => '01O', 'status' => 'confirmed', 'payment' => ['method' => 'online'], 'totals' => ['grand_total' => moneyBody('5040.00')]]]]);

    same('placed', Checkout::place($checkout, null)['outcome']);
});

test('only sends a return url that is https', function () {
    Env::set('APP_URL', 'http://localhost:8080');
    same(null, Checkout::returnUrl());

    Env::set('APP_URL', 'https://shop.test/');
    same('https://shop.test/payment/return', Checkout::returnUrl());
});

suite('Orders');

function rememberOrder(string $phone = '01712-345678'): array
{
    freshDatabase();

    return Orders::remember(
        ['id' => '01ORDER', 'reference' => 'ORD-260901-ABCD', 'status' => 'confirmed', 'placed_at' => gmdate('c'), 'totals' => ['grand_total' => moneyBody('5040.00')]],
        ['reference' => 'SF-2026-000001', 'payment_method' => 'cod', 'customer_json' => ['name' => 'Nusrat', 'phone' => $phone, 'email' => null]],
    );
}

test('finds an order by reference and the mobile number, however it was typed', function () {
    rememberOrder('01712-345678');

    ok(Orders::lookup('ORD-260901-ABCD', '+8801712345678') !== null);
    ok(Orders::lookup('SF-2026-000001', '01712 345678') !== null);
    same(null, Orders::lookup('ORD-260901-ABCD', '01712345679'), 'wrong number');
    same(null, Orders::lookup('ORD-NOPE', '01712345678'), 'unknown reference');
    same(null, Orders::lookup('ORD-260901-ABCD', '123'), 'too short to mean anything');
});

test('grants an order by its token or session grant only, never by its id', function () {
    $order = rememberOrder();

    same(null, Orders::authorised('01ORDER', null, null));
    same(null, Orders::authorised('01ORDER', 'wrong', null));
    ok(Orders::authorised('01ORDER', (string) $order['access_token'], null) !== null);
    ok(Orders::authorised('01ORDER', null, (string) $order['access_token']) !== null);
    same(null, Orders::authorised('01OTHER', (string) $order['access_token'], null));
});

test('counts attempts and says when there have been too many', function () {
    freshDatabase();

    for ($i = 0; $i < 3; $i++) {
        ok(Throttle::hit('t:test', 3, 60));
    }

    ok(! Throttle::hit('t:test', 3, 60));
    ok(Throttle::hit('t:other', 3, 60), 'a different bucket is separate');
});

suite('Webhooks');

function signedWebhook(string $body, ?int $timestamp = null, string $secret = 'whsec_current_secret_value'): array
{
    $timestamp ??= time();

    return [$body, 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), (string) $timestamp];
}

test('accepts a genuine signature and refuses everything else', function () {
    [$body, $signature, $timestamp] = signedWebhook('{"event":"product.updated","data":{}}');

    ok(Webhooks::verify($body, $signature, $timestamp));
    ok(! Webhooks::verify($body.' ', $signature, $timestamp), 'tampered body');
    ok(! Webhooks::verify($body, 'sha256='.str_repeat('0', 64), $timestamp), 'wrong signature');
    ok(! Webhooks::verify($body, null, $timestamp), 'no signature');
    ok(! Webhooks::verify($body, $signature, null), 'no timestamp');
    ok(! Webhooks::verify($body, $signature, 'abc'), 'garbage timestamp');
});

test('rejects anything older than five minutes, or from the future', function () {
    [$body, $old, $oldTs] = signedWebhook('{"event":"x"}', time() - 301);
    ok(! Webhooks::verify($body, $old, $oldTs));

    [$body, $future, $futureTs] = signedWebhook('{"event":"x"}', time() + 301);
    ok(! Webhooks::verify($body, $future, $futureTs));

    [$body, $fresh, $freshTs] = signedWebhook('{"event":"x"}', time() - 250);
    ok(Webhooks::verify($body, $fresh, $freshTs));
});

test('accepts the previous secret during a rotation', function () {
    Env::set('WEBHOOK_SECRET_PREVIOUS', 'whsec_retiring_secret');

    [$body, $signature, $timestamp] = signedWebhook('{"event":"x"}', null, 'whsec_retiring_secret');
    ok(Webhooks::verify($body, $signature, $timestamp));

    Env::set('WEBHOOK_SECRET_PREVIOUS', '');
    ok(! Webhooks::verify($body, $signature, $timestamp), 'retired once the window closes');
});

test('applies an event once, answers a repeat as a duplicate, and drops the cache', function () {
    freshDatabase();
    ApiCache::remember('products:abc', 'catalog', 300, fn () => ['data' => ['x']]);

    $body = json_encode(['event' => 'product.updated', 'occurred_at' => gmdate('c'), 'data' => ['id' => '01P']]);
    [, $signature, $timestamp] = signedWebhook($body);

    $first = Webhooks::receive($body, $signature, $timestamp, '01DELIVERY0001', 'product.updated');

    same(200, $first['status']);
    same('ok', $first['body']['status']);
    same(0, (int) Db::value('SELECT COUNT(*) FROM api_cache'), 'catalogue cache dropped');

    $second = Webhooks::receive($body, $signature, $timestamp, '01DELIVERY0001', 'product.updated');
    same('duplicate', $second['body']['status']);
});

test('moves a local order along when the ERP says its status changed', function () {
    rememberOrder();

    $body = json_encode(['event' => 'order.status_changed', 'data' => ['order' => ['id' => '01ORDER', 'status' => 'shipped']]]);
    [, $signature, $timestamp] = signedWebhook($body);

    same(200, Webhooks::receive($body, $signature, $timestamp, '01DELIVERY0002', 'order.status_changed')['status']);
    same('shipped', Orders::find('01ORDER')['status']);
});

test('answers 401 to a bad signature and 400 to an unreadable body', function () {
    [$body, , $timestamp] = signedWebhook('{"event":"x"}');

    same(401, Webhooks::receive($body, 'sha256=bad', $timestamp, '01DELIVERY0003', 'x')['status']);

    [$junk, $signature, $junkTs] = signedWebhook('not json');
    same(400, Webhooks::receive($junk, $signature, $junkTs, '01DELIVERY0004', 'x')['status']);
});
