<?php

declare(strict_types=1);

use Storefront\App;
use Storefront\Domain\Cart;
use Storefront\Domain\Orders;
use Storefront\Http\Csrf;
use Storefront\Http\Response;
use Storefront\Http\Session;
use Storefront\Kernel;
use Storefront\Support\Db;

suite('HTTP');

/**
 * A shop whose ERP answers with a site, a category, and the given products.
 *
 * @param  list<array<string, mixed>>  $products
 */
function shop(array $products = [], array $site = []): FakeErp
{
    freshDatabase();
    $_SESSION = [];

    $erp = (new FakeErp)
        ->on('GET /site', fn () => [200, siteBody($site)])
        ->on('GET /categories', fn () => [200, ['data' => [['id' => '01C', 'slug' => 'panjabi', 'name' => 'Panjabi']], 'meta' => ['has_more' => false, 'next_cursor' => null]]])
        ->on('GET /products', fn () => [200, ['data' => $products, 'meta' => ['has_more' => false, 'next_cursor' => null]]])
        ->on('GET /products/facets', fn () => [200, ['data' => ['brands' => [], 'attributes' => [], 'price_range' => null]]]);

    foreach ($products as $product) {
        // The ERP's single-product answer is the product itself, not wrapped in `data`.
        $erp->on('GET /products/'.$product['slug'], fn () => [200, $product]);
    }

    App::useErp($erp->client());

    return $erp;
}

function html(Response $response): string
{
    return $response->body;
}

test('the home page shows the shop and its products, escaped', function () {
    $evil = productBody('evil', 'EV-1');
    $evil['name'] = '<script>alert(1)</script>Shirt';

    shop([productBody(), $evil]);

    $response = Kernel::handle(makeRequest('GET', '/'));
    $body = html($response);

    same(200, $response->status);
    contains('Test Shop', $body);
    contains('Cotton Panjabi', $body);
    contains('&lt;script&gt;alert(1)&lt;/script&gt;Shirt', $body, 'product copy is escaped');
    lacks('<script>alert(1)', $body);
    contains('৳2,490.00', $body, 'the ERP’s own price');
    contains('--brand:#112233', $body, 'branding becomes a stylesheet variable');
});

test('a product page offers the variants and an add-to-cart form with a CSRF token', function () {
    shop([productBody()]);

    $body = html(Kernel::handle(makeRequest('GET', '/product/cotton-panjabi')));

    contains('Cotton Panjabi', $body);
    contains('name="sku"', $body);
    contains('name="_token"', $body);
    contains('Line one.', $body);
});

test('an unknown product is a 404, not a fault', function () {
    shop([]);

    $response = Kernel::handle(makeRequest('GET', '/product/nope'));

    same(404, $response->status);
});

test('a closed shop answers every page with that, and says when to come back', function () {
    shop([], ['status' => 'maintenance', 'maintenance_message' => 'Back at 6pm']);

    $response = Kernel::handle(makeRequest('GET', '/'));

    same(503, $response->status);
    contains('Back at 6pm', html($response));
    same('120', $response->headers['Retry-After']);
});

test('an unreachable ERP shows a calm page, not an error', function () {
    freshDatabase();
    $_SESSION = [];
    App::useErp((new FakeErp)->client()); // answers 404 to everything, so no site is known

    $response = Kernel::handle(makeRequest('GET', '/'));

    same(503, $response->status);
    lacks('Exception', html($response));
});

test('a POST without the CSRF token changes nothing', function () {
    shop([productBody()]);

    $response = Kernel::handle(makeRequest('POST', '/cart/add', ['product' => 'cotton-panjabi', 'sku' => 'FW-1-M', 'quantity' => '1']));

    same(303, $response->status);
    same(0, (int) Db::value('SELECT COUNT(*) FROM carts'));
});

test('adding to the cart reads the product from the ERP, not from the form', function () {
    $erp = shop([productBody()]);
    Kernel::handle(makeRequest('GET', '/')); // starts the session, which holds the CSRF token

    $token = Csrf::token();

    // The form claims a SKU and a name; only the SKU is used, and only because
    // the ERP's product lists it.
    $response = Kernel::handle(makeRequest('POST', '/cart/add', [
        '_token' => $token, 'product' => 'cotton-panjabi', 'sku' => 'fw-1-m', 'quantity' => '2', 'name' => 'Free Gift', 'price' => '1',
    ]));

    same(303, $response->status);
    same('/cart', $response->headers['Location']);

    $row = Db::one('SELECT * FROM cart_items');

    same('FW-1-M', $row['sku']);
    same('Cotton Panjabi', $row['product_name'], 'the name came from the ERP');
    same(2, (int) $row['quantity']);

    // A SKU the product does not have is refused.
    Kernel::handle(makeRequest('POST', '/cart/add', ['_token' => $token, 'product' => 'cotton-panjabi', 'sku' => 'NOT-A-SKU', 'quantity' => '1']));
    same(1, (int) Db::value('SELECT COUNT(*) FROM cart_items'));
});

test('a sold-out variant cannot be added', function () {
    shop([productBody('sold-out', 'SO-1', false)]);
    Kernel::handle(makeRequest('GET', '/'));

    Kernel::handle(makeRequest('POST', '/cart/add', ['_token' => Csrf::token(), 'product' => 'sold-out', 'sku' => 'SO-1', 'quantity' => '1']));

    same(0, (int) Db::value('SELECT COUNT(*) FROM cart_items'));
});

test('the quantity is held to the product’s own limits', function () {
    shop([productBody()]);
    Kernel::handle(makeRequest('GET', '/'));

    Kernel::handle(makeRequest('POST', '/cart/add', ['_token' => Csrf::token(), 'product' => 'cotton-panjabi', 'sku' => 'FW-1-M', 'quantity' => '99']));

    same(10, (int) Db::value('SELECT quantity FROM cart_items'), 'capped at max_order_quantity');
});

test('the cart page prices lines from a quote, never from the basket', function () {
    $erp = shop([productBody()]);
    $erp->on('POST /checkout/quote', fn () => [200, ['data' => [
        'currency' => 'BDT',
        'items' => [['sku' => 'FW-1-M', 'quantity' => 2, 'unit_price' => moneyBody('2490.00'), 'line_total' => moneyBody('4980.00'), 'availability' => ['in_stock' => true, 'quantity' => 12, 'reservable' => true]]],
        'subtotal' => moneyBody('4980.00'), 'discount' => moneyBody('0.00'), 'shipping' => moneyBody('60.00'), 'tax' => moneyBody('0.00'),
        'grand_total' => moneyBody('5040.00'),
    ]]]);

    $cart = Cart::forToken(null);
    $cart->add('FW-1-M', 2, ['product_id' => '01P', 'slug' => 'cotton-panjabi', 'name' => 'Cotton Panjabi', 'variant' => 'M / Navy', 'image' => null]);

    $body = html(Kernel::handle(makeRequest('GET', '/cart', [], [], '', [Cart::COOKIE => $cart->token()])));

    contains('৳5,040.00', $body);
    contains('৳4,980.00', $body);
    contains('M / Navy', $body);
});

test('the webhook endpoint needs a valid signature and starts no session', function () {
    shop([]);
    $body = '{"event":"product.updated","data":{}}';
    $timestamp = (string) time();
    $good = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_current_secret_value');

    $bad = Kernel::handle(makeRequest('POST', '/webhooks/feriwala', [], ['x-feriwala-signature' => 'sha256=00', 'x-feriwala-timestamp' => $timestamp, 'x-feriwala-delivery' => '01HOOKTEST0001'], $body));
    same(401, $bad->status);

    $ok = Kernel::handle(makeRequest('POST', '/webhooks/feriwala', [], ['x-feriwala-signature' => $good, 'x-feriwala-timestamp' => $timestamp, 'x-feriwala-delivery' => '01HOOKTEST0001'], $body));
    same(200, $ok->status);
});

test('an order page is not reachable by its id alone', function () {
    shop([]);
    Orders::remember(
        ['id' => '01ORDERHTTP', 'reference' => 'ORD-1', 'status' => 'confirmed', 'placed_at' => gmdate('c'), 'totals' => ['grand_total' => moneyBody('100.00')]],
        ['reference' => 'SF-2026-000009', 'payment_method' => 'cod', 'customer_json' => ['name' => 'N', 'phone' => '01712345678', 'email' => null]],
    );

    same(404, Kernel::handle(makeRequest('GET', '/order/01ORDERHTTP'))->status);
    same(404, Kernel::handle(makeRequest('GET', '/order/01ORDERHTTP', ['t' => 'guess']))->status);
});

test('the tracking form says the same thing for a wrong number as for no order', function () {
    shop([]);
    Orders::remember(
        ['id' => '01ORDERTRK', 'reference' => 'ORD-TRK', 'status' => 'confirmed', 'placed_at' => gmdate('c'), 'totals' => ['grand_total' => moneyBody('100.00')]],
        ['reference' => 'SF-2026-000010', 'payment_method' => 'cod', 'customer_json' => ['name' => 'N', 'phone' => '01712345678', 'email' => null]],
    );

    Kernel::handle(makeRequest('GET', '/track'));
    $token = Csrf::token();

    Kernel::handle(makeRequest('POST', '/track', ['_token' => $token, 'reference' => 'ORD-TRK', 'mobile' => '01799999999']));
    $wrong = Session::pullFlash();

    Kernel::handle(makeRequest('POST', '/track', ['_token' => $token, 'reference' => 'ORD-NOPE', 'mobile' => '01712345678']));
    $missing = Session::pullFlash();

    same($wrong, $missing, 'indistinguishable');

    $found = Kernel::handle(makeRequest('POST', '/track', ['_token' => $token, 'reference' => 'ORD-TRK', 'mobile' => '+8801712345678']));
    same('/order/01ORDERTRK', $found->headers['Location']);
});

test('the area directory is proxied and cached, with nothing but data in it', function () {
    $erp = shop([]);
    $erp->on('GET /delivery/areas', fn () => [200, ['data' => [['id' => 'division:1', 'type' => 'division', 'name' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'has_children' => true]]]]);

    $first = Kernel::handle(makeRequest('GET', '/api/areas'));
    Kernel::handle(makeRequest('GET', '/api/areas'));

    same(200, $first->status);
    contains('Dhaka', $first->body);
    same(1, count($erp->calls('GET', '/delivery/areas')), 'the second read came from the cache');

    same(422, Kernel::handle(makeRequest('GET', '/api/areas', ['parent' => '../../etc']))->status);
});

test('every page carries the security headers once sent through the front controller', function () {
    // Headers are added in public/index.php; assert they are written there.
    $source = (string) file_get_contents(dirname(__DIR__).'/public/index.php');

    foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Content-Security-Policy', 'Referrer-Policy'] as $header) {
        contains($header, $source);
    }
});
