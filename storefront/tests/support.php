<?php

declare(strict_types=1);

use Storefront\Erp\Client;
use Storefront\Http\Request;
use Storefront\Support\Db;

/**
 * Wipe the storefront's tables between tests.
 */
function freshDatabase(): void
{
    foreach (['cart_items', 'carts', 'checkouts', 'orders', 'api_cache', 'webhook_deliveries', 'throttle', 'sessions'] as $table) {
        Db::exec('DELETE FROM '.$table);
    }
}

/**
 * A fake ERP: routes `"METHOD /path"` to a callable returning
 * `[status, body, headers?]` — and a log of every request it saw.
 */
final class FakeErp
{
    /** @var array<string, callable> */
    public array $routes = [];

    /** @var list<array{method: string, path: string, query: string, headers: array<string, string>, body: array<string, mixed>|null}> */
    public array $seen = [];

    public function on(string $route, callable $handler): self
    {
        $this->routes[$route] = $handler;

        return $this;
    }

    public function client(): Client
    {
        return (new Client('https://erp.test', 'wsk_TESTKEY00000000000000000AB', 'test-secret', null, 5, [$this, 'handle']))->withoutBackoff();
    }

    /**
     * @param  array<int, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $parts = parse_url($url);
        $path = (string) $parts['path'];
        $query = (string) ($parts['query'] ?? '');
        $named = [];

        foreach ($headers as $line) {
            [$name, $value] = explode(':', $line, 2);
            $named[strtolower(trim($name))] = trim($value);
        }

        $decoded = $body === null ? null : json_decode($body, true);
        $this->seen[] = ['method' => $method, 'path' => $path, 'query' => $query, 'headers' => $named, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $body];

        $short = str_replace('/api/storefront/v1', '', $path);
        $handler = $this->routes[$method.' '.$short] ?? null;

        if ($handler === null) {
            return ['status' => 404, 'headers' => [], 'body' => json_encode(['error' => ['code' => 'not_found', 'message' => 'No route '.$method.' '.$short]])];
        }

        $result = $handler($decoded, $query, $named);

        return [
            'status' => $result[0],
            'headers' => array_change_key_case($result[2] ?? [], CASE_LOWER),
            'body' => json_encode($result[1]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calls(string $method, string $path): array
    {
        return array_values(array_filter($this->seen, static fn (array $call) => $call['method'] === $method && str_ends_with($call['path'], $path)));
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function siteBody(array $overrides = []): array
{
    return ['data' => array_replace_recursive([
        'id' => '01TESTSITE',
        'name' => 'Test Shop',
        'status' => 'open',
        'accepting_orders' => true,
        'maintenance_message' => null,
        'hosts' => ['primary' => 'shop.test', 'subdomain' => 'shop.test', 'aliases' => []],
        'branding' => ['primary_color' => '#112233', 'secondary_color' => '#aa5500', 'logo_url' => null, 'banner_url' => null, 'tagline' => 'Good things', 'about' => 'About us text.'],
        'contact' => ['email' => 'hello@shop.test', 'phone' => '01700000000', 'address' => 'Dhaka'],
        'locale' => ['default' => 'en', 'available' => ['en', 'bn']],
        'currency' => 'BDT',
        'checkout' => ['payment_methods' => ['online', 'cod'], 'cod' => ['enabled' => true, 'maximum' => null], 'guest_checkout' => true],
    ], $overrides)];
}

/**
 * @return array<string, mixed>
 */
function moneyBody(string $amount): array
{
    return ['amount' => $amount, 'currency' => 'BDT', 'formatted' => '৳'.number_format((float) $amount, 2)];
}

/**
 * @return array<string, mixed>
 */
function productBody(string $slug = 'cotton-panjabi', string $sku = 'FW-1-M', bool $inStock = true): array
{
    return [
        'id' => '01PRODUCT'.strtoupper(substr(md5($slug), 0, 10)),
        'slug' => $slug,
        'sku' => 'FW-1',
        'name' => 'Cotton Panjabi',
        'short_description' => 'Soft cotton.',
        'description' => "Line one.\n\nLine two.",
        'brand' => ['id' => '01B', 'name' => 'Aarong', 'slug' => 'aarong'],
        'categories' => [['id' => '01C', 'slug' => 'panjabi', 'name' => 'Panjabi']],
        'media' => [['url' => 'https://cdn.test/a.jpg', 'alt' => 'front', 'position' => 1, 'type' => 'image']],
        'variants' => [[
            'id' => '01V1', 'sku' => $sku, 'attributes' => ['size' => 'M', 'colour' => 'Navy'],
            'price' => moneyBody('2490.00'), 'compare_at_price' => null,
            'availability' => ['in_stock' => $inStock, 'quantity' => $inStock ? 12 : 0, 'backorderable' => false],
        ]],
        'min_order_quantity' => 1,
        'max_order_quantity' => 10,
        'seo' => ['title' => 'Cotton Panjabi', 'description' => 'Soft cotton.'],
    ];
}

/**
 * @param  array<string, mixed>  $input
 * @param  array<string, string>  $headers
 */
function makeRequest(string $method, string $path, array $input = [], array $headers = [], string $body = '', array $cookies = []): Request
{
    return new Request(
        $method,
        $path,
        $method === 'GET' ? $input : [],
        $method === 'POST' ? $input : [],
        array_change_key_case($headers, CASE_LOWER),
        ['REMOTE_ADDR' => '203.0.113.9', 'HTTPS' => 'on'],
        $cookies,
        $body,
    );
}
