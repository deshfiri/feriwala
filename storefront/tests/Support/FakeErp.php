<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * A fake ERP built on Http::fake(): routes `"METHOD /path"` (relative to
 * /api/storefront/v1) to a callable returning `[status, body, headers?]`, and a
 * log of every request it saw. Also the one home of the test fixtures.
 */
final class FakeErp
{
    /** @var array<string, callable> */
    public array $routes = [];

    /** @var list<array{method: string, path: string, query: string, headers: array<string, string>, body: array<string, mixed>|null, raw: string}> */
    public array $seen = [];

    /**
     * Intercept every outgoing HTTP request, and make retries instant.
     */
    public static function install(): self
    {
        $erp = new self;

        Sleep::fake();
        Http::fake(fn (Request $request) => $erp->handle($request));

        return $erp;
    }

    /**
     * A shop whose ERP answers with a site, a category, and the given products.
     *
     * @param  list<array<string, mixed>>  $products
     * @param  array<string, mixed>  $site
     */
    public static function shop(array $products = [], array $site = []): self
    {
        $erp = self::install()
            ->on('GET /site', fn () => [200, self::siteBody($site)])
            ->on('GET /categories', fn () => [200, ['data' => [['id' => '01C', 'slug' => 'panjabi', 'name' => 'Panjabi']], 'meta' => ['has_more' => false, 'next_cursor' => null]]])
            ->on('GET /products', fn () => [200, ['data' => $products, 'meta' => ['has_more' => false, 'next_cursor' => null]]])
            ->on('GET /products/facets', fn () => [200, ['data' => ['brands' => [], 'attributes' => [], 'price_range' => null]]]);

        foreach ($products as $product) {
            // The ERP's single-product answer is the product itself, not wrapped in `data`.
            $erp->on('GET /products/'.$product['slug'], fn () => [200, $product]);
        }

        return $erp;
    }

    public function on(string $route, callable $handler): self
    {
        $this->routes[$route] = $handler;

        return $this;
    }

    /**
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function handle(Request $request)
    {
        $parts = parse_url($request->url());
        $path = (string) $parts['path'];
        $query = (string) ($parts['query'] ?? '');
        $named = [];

        foreach ($request->headers() as $name => $values) {
            $named[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        $raw = $request->body();
        $decoded = $raw === '' ? null : json_decode($raw, true);
        $this->seen[] = ['method' => $request->method(), 'path' => $path, 'query' => $query, 'headers' => $named, 'body' => is_array($decoded) ? $decoded : null, 'raw' => $raw];

        $short = str_replace('/api/storefront/v1', '', $path);
        $handler = $this->routes[$request->method().' '.$short] ?? null;

        if ($handler === null) {
            return Http::response(['error' => ['code' => 'not_found', 'message' => 'No route '.$request->method().' '.$short]], 404);
        }

        $result = $handler(is_array($decoded) ? $decoded : null, $query, $named);

        return Http::response($result[1], $result[0], $result[2] ?? []);
    }

    /**
     * @return list<array{method: string, path: string, query: string, headers: array<string, string>, body: array<string, mixed>|null, raw: string}>
     */
    public function calls(string $method, string $path): array
    {
        return array_values(array_filter($this->seen, static fn (array $call) => $call['method'] === $method && str_ends_with($call['path'], $path)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function siteBody(array $overrides = []): array
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
    public static function moneyBody(string $amount): array
    {
        return ['amount' => $amount, 'currency' => 'BDT', 'formatted' => '৳'.number_format((float) $amount, 2)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function productBody(string $slug = 'cotton-panjabi', string $sku = 'FW-1-M', bool $inStock = true, array $overrides = []): array
    {
        return array_replace([
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
                'price' => self::moneyBody('2490.00'), 'compare_at_price' => null,
                'availability' => ['in_stock' => $inStock, 'quantity' => $inStock ? 12 : 0, 'backorderable' => false],
            ]],
            'min_order_quantity' => 1,
            'max_order_quantity' => 10,
            'seo' => ['title' => 'Cotton Panjabi', 'description' => 'Soft cotton.'],
        ], $overrides);
    }

    /**
     * What `POST /checkout/quote` answers (the `data` of it), for FW-1-M x $quantity at 2,490.00 plus 60.00 shipping.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function quoteData(int $quantity = 2, array $overrides = []): array
    {
        $line = number_format(2490 * $quantity, 2, '.', '');
        $total = number_format(2490 * $quantity + 60, 2, '.', '');

        return array_replace([
            'currency' => 'BDT',
            'items' => [[
                'sku' => 'FW-1-M', 'quantity' => $quantity,
                'unit_price' => self::moneyBody('2490.00'), 'line_total' => self::moneyBody($line),
                'availability' => ['in_stock' => true, 'quantity' => 12, 'reservable' => true],
            ]],
            'subtotal' => self::moneyBody($line), 'discount' => self::moneyBody('0.00'), 'shipping' => self::moneyBody('60.00'),
            'tax' => self::moneyBody('0.00'), 'tax_included' => self::moneyBody('0.00'), 'grand_total' => self::moneyBody($total),
            'payment' => ['methods' => ['online', 'cod'], 'cod' => ['enabled' => true, 'eligible' => true, 'maximum' => null]],
            'quoted_at' => gmdate('c'), 'valid_until' => gmdate('c', time() + 600),
        ], $overrides);
    }

    /**
     * An order as the ERP reads it back (`GET /orders/{id}`).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function orderBody(array $overrides = []): array
    {
        return array_replace([
            'id' => '01ORDER',
            'reference' => 'ORD-260901-ABCD',
            'status' => 'confirmed',
            'placed_at' => gmdate('c'),
            'items' => [['sku' => 'FW-1-M', 'name' => 'Cotton Panjabi', 'variant' => 'M / Navy', 'quantity' => 2, 'unit_price' => self::moneyBody('2490.00'), 'line_total' => self::moneyBody('4980.00')]],
            'totals' => ['subtotal' => self::moneyBody('4980.00'), 'discount' => self::moneyBody('0.00'), 'shipping' => self::moneyBody('60.00'), 'tax' => self::moneyBody('0.00'), 'grand_total' => self::moneyBody('5040.00')],
            'payment' => ['method' => 'cod'],
            'timeline' => [['status' => 'confirmed', 'at' => gmdate('c'), 'note' => 'Thanks for ordering']],
        ], $overrides);
    }
}
