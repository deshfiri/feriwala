<?php

use App\Models\Checkout;
use App\Models\Order;
use App\Services\Basket;
use App\Services\Orders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;
use Tests\Support\FakeErp;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Unit');

/*
| Helpers are prefixed `storefront…` because Pest loads every test file into one
| global function namespace.
*/

/**
 * A basket holding the given lines; returns its cookie token.
 *
 * @param  list<array{0: string, 1: int}>  $lines  `[sku, quantity]`
 */
function storefrontBasket(array $lines = [['FW-1-M', 2]]): string
{
    $basket = Basket::forToken(null);

    foreach ($lines as [$sku, $quantity]) {
        $basket->add($sku, $quantity, ['product_id' => '01P', 'slug' => 'cotton-panjabi', 'name' => 'Cotton Panjabi', 'variant' => 'M / Navy', 'image' => null]);
    }

    return $basket->ensureToken();
}

/**
 * GET as a visitor with the given basket cookie and session.
 *
 * @param  array<string, mixed>  $session
 * @param  array<string, string>  $cookies
 */
function storefrontGet(FoundationTestCase $test, string $uri, array $session = [], array $cookies = []): Illuminate\Testing\TestResponse
{
    return $test->withSession($session)->withUnencryptedCookies($cookies)->get($uri);
}

/**
 * POST with a valid CSRF token in the session and the form. The token check
 * itself is exercised in StorefrontHttpTest.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $session
 * @param  array<string, string>  $cookies
 */
function storefrontPost(FoundationTestCase $test, string $uri, array $data = [], array $session = [], array $cookies = []): Illuminate\Testing\TestResponse
{
    return $test->withSession(['_token' => 'tok'] + $session)->withUnencryptedCookies($cookies)->post($uri, ['_token' => 'tok'] + $data);
}

/**
 * The messages queued by flash_message() in the last response's session.
 *
 * @return list<string>
 */
function storefrontFlashes(): array
{
    return array_map(static fn (array $flash): string => $flash['message'], (array) session('_flash', []));
}

/**
 * The details a customer types at checkout.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function storefrontDetails(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Nusrat Jahan', 'phone' => '01712-345678', 'email' => '', 'line1' => 'House 1, Road 2',
        'city' => 'Dhaka', 'district' => 'Dhaka', 'payment_method' => 'cod',
    ];
}

/**
 * A checkout row, as Checkouts::create would leave it.
 *
 * @param  array<string, mixed>  $overrides
 */
function storefrontCheckout(array $overrides = []): Checkout
{
    return Checkout::query()->create($overrides + [
        'token' => bin2hex(random_bytes(20)),
        'idempotency_key' => 'b3c5f7a0-1111-4222-8333-444455556666',
        'reference' => 'SF-2026-000001',
        'status' => Checkout::DRAFT,
        'customer_json' => ['name' => 'Nusrat', 'phone' => '01712-345678', 'email' => null],
        'shipping_json' => ['line1' => 'House 1', 'city' => 'Dhaka', 'country' => 'BD'],
        'items_json' => [['sku' => 'FW-1-M', 'quantity' => 2, 'name' => 'Cotton Panjabi', 'variant' => 'M / Navy', 'image' => null, 'slug' => 'cotton-panjabi']],
        'payment_method' => 'cod',
    ]);
}

/**
 * An order the storefront has remembered, as if just placed.
 */
function storefrontRememberedOrder(string $phone = '01712-345678', string $publicId = '01ORDER', string $reference = 'ORD-260901-ABCD', string $storefrontReference = 'SF-2026-000001'): Order
{
    $checkout = storefrontCheckout([
        'reference' => $storefrontReference,
        'customer_json' => ['name' => 'Nusrat', 'phone' => $phone, 'email' => null],
    ]);

    return app(Orders::class)->remember(
        ['id' => $publicId, 'reference' => $reference, 'status' => 'confirmed', 'placed_at' => gmdate('c'), 'totals' => ['grand_total' => FakeErp::moneyBody('5040.00')]],
        $checkout,
    );
}

/**
 * Headers of a webhook delivery, in the form a request sends them.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function storefrontSignedWebhook(string $body, ?int $timestamp = null, string $secret = 'whsec_test_secret_0123456789', string $delivery = '01HOOKTEST0001', string $event = ''): array
{
    $timestamp ??= time();

    return [$body, [
        'HTTP_X_FERIWALA_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        'HTTP_X_FERIWALA_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_FERIWALA_DELIVERY' => $delivery,
        'HTTP_X_FERIWALA_EVENT' => $event,
    ]];
}
