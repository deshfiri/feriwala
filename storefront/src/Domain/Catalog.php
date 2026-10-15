<?php

declare(strict_types=1);

namespace Storefront\Domain;

use Storefront\App;
use Storefront\Erp\ErpException;
use Storefront\Support\ApiCache;
use Storefront\Support\Env;

/**
 * Reading the catalogue (contract §5.1).
 *
 * Cached briefly and invalidated by webhook — but never trusted at checkout,
 * where the ERP prices the cart again. Every list is paginated by cursor; the
 * ERP has no page number and this does not invent one.
 */
final class Catalog
{
    public const TAG = 'catalog';

    /** Filters the list endpoint understands; anything else is dropped, not forwarded. */
    private const FILTERS = ['q', 'sort', 'price_min', 'price_max', 'category', 'brand', 'in_stock', 'featured', 'flash_deal'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{products: list<array<string, mixed>>, next_cursor: string|null, has_more: bool}
     */
    public static function products(array $filters = [], ?string $cursor = null, int $limit = 24): array
    {
        $query = self::query($filters) + ['limit' => max(1, min(48, $limit))];

        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }

        $response = self::cached('products:'.md5(json_encode($query) ?: ''), self::TAG, Env::int('CACHE_TTL_CATALOG', 60), fn () => App::erp()->get('/products', $query));

        $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];

        return [
            'products' => array_values(array_filter(is_array($response['data'] ?? null) ? $response['data'] : [], 'is_array')),
            'next_cursor' => is_string($meta['next_cursor'] ?? null) ? $meta['next_cursor'] : null,
            'has_more' => (bool) ($meta['has_more'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function facets(array $filters = []): array
    {
        $query = self::query($filters);

        try {
            $response = self::cached('facets:'.md5(json_encode($query) ?: ''), self::TAG, Env::int('CACHE_TTL_CATALOG', 60), fn () => App::erp()->get('/products/facets', $query));
        } catch (ErpException) {
            return ['brands' => [], 'attributes' => [], 'price_range' => null];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return [
            'brands' => array_values(array_filter(is_array($data['brands'] ?? null) ? $data['brands'] : [], 'is_array')),
            'attributes' => array_values(array_filter(is_array($data['attributes'] ?? null) ? $data['attributes'] : [], 'is_array')),
            'price_range' => is_array($data['price_range'] ?? null) ? $data['price_range'] : null,
        ];
    }

    /**
     * One product by slug or id, or null when this website does not sell it.
     *
     * @return array<string, mixed>|null
     */
    public static function product(string $slugOrId, bool $fresh = false): ?array
    {
        $key = 'product:'.$slugOrId;

        if ($fresh) {
            ApiCache::forgetKey($key);
        }

        try {
            $response = self::cached($key, self::TAG, Env::int('CACHE_TTL_PRODUCT', 120), fn () => App::erp()->get('/products/'.rawurlencode($slugOrId)));
        } catch (ErpException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }

        // The ERP answers a single product bare, a list under `data`; either
        // is read, so a wrapped answer is not mistaken for "no such product".
        $product = is_array($response['data'] ?? null) ? $response['data'] : $response;

        return isset($product['slug']) || isset($product['id']) ? $product : null;
    }

    /**
     * Every category, walked by cursor and kept as a tree-ready flat list.
     *
     * @return list<array<string, mixed>>
     */
    public static function categories(): array
    {
        try {
            $response = ApiCache::remember('categories', self::TAG, Env::int('CACHE_TTL_CATEGORIES', 600), function (): array {
                $all = [];
                $cursor = null;

                for ($page = 0; $page < 20; $page++) {
                    $answer = App::erp()->get('/categories', ['limit' => 200, 'cursor' => $cursor]);

                    foreach (is_array($answer['data'] ?? null) ? $answer['data'] : [] as $category) {
                        if (is_array($category)) {
                            $all[] = $category;
                        }
                    }

                    $meta = is_array($answer['meta'] ?? null) ? $answer['meta'] : [];

                    if (! ($meta['has_more'] ?? false) || ! is_string($meta['next_cursor'] ?? null)) {
                        break;
                    }

                    $cursor = $meta['next_cursor'];
                }

                return ['data' => $all];
            });
        } catch (ErpException|\RuntimeException) {
            return [];
        }

        return array_values(array_filter($response['data'] ?? [], 'is_array'));
    }

    /**
     * Advisory availability for some SKUs. Never a promise (§5.2).
     *
     * @param  list<string>  $skus
     * @return array<string, array<string, mixed>>
     */
    public static function inventory(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        try {
            $response = App::erp()->get('/inventory', ['skus' => array_values($skus), 'limit' => 200]);
        } catch (ErpException) {
            return [];
        }

        $out = [];

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $row) {
            if (is_array($row) && is_string($row['sku'] ?? null)) {
                $out[$row['sku']] = $row;
            }
        }

        return $out;
    }

    /**
     * The first image, for a card or a basket line.
     *
     * @param  array<string, mixed>  $product
     */
    public static function primaryImage(array $product): ?string
    {
        $media = is_array($product['media'] ?? null) ? $product['media'] : [];
        $best = null;

        foreach ($media as $item) {
            if (! is_array($item) || ($item['type'] ?? 'image') !== 'image' || ! is_string($item['url'] ?? null)) {
                continue;
            }

            if ($best === null || (int) ($item['position'] ?? 999) < (int) ($best['position'] ?? 999)) {
                $best = $item;
            }
        }

        return $best === null ? null : (string) $best['url'];
    }

    /**
     * The cheapest variant price, for "from ৳…" on a card.
     *
     * @param  array<string, mixed>  $product
     * @return array<string, mixed>|null
     */
    public static function startingPrice(array $product): ?array
    {
        $best = null;

        foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
            $price = is_array($variant) && is_array($variant['price'] ?? null) ? $variant['price'] : null;

            if ($price === null) {
                continue;
            }

            // Decimal strings compared as decimals, never as floats.
            if ($best === null || self::decimalLess((string) $price['amount'], (string) $best['amount'])) {
                $best = $price;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    public static function inStock(array $product): bool
    {
        foreach (is_array($product['variants'] ?? null) ? $product['variants'] : [] as $variant) {
            if (is_array($variant) && ($variant['availability']['in_stock'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare two decimal strings exactly.
     */
    public static function decimalLess(string $a, string $b): bool
    {
        [$ai, $af] = array_pad(explode('.', $a, 2), 2, '0');
        [$bi, $bf] = array_pad(explode('.', $b, 2), 2, '0');

        $ai = ltrim($ai, '0') ?: '0';
        $bi = ltrim($bi, '0') ?: '0';

        if (strlen($ai) !== strlen($bi)) {
            return strlen($ai) < strlen($bi);
        }

        if ($ai !== $bi) {
            return strcmp($ai, $bi) < 0;
        }

        $len = max(strlen($af), strlen($bf));

        return strcmp(str_pad($af, $len, '0'), str_pad($bf, $len, '0')) < 0;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function query(array $filters): array
    {
        $query = [];

        foreach (self::FILTERS as $name) {
            $value = $filters[$name] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $query[$name] = trim((string) $value);
            }
        }

        // Variation attributes: a handful, by slug, as the contract allows.
        if (is_array($filters['attributes'] ?? null)) {
            $attributes = [];

            foreach (array_slice($filters['attributes'], 0, 5, true) as $slug => $value) {
                if (is_string($slug) && preg_match('/^[a-z0-9_-]{1,64}$/', $slug) === 1 && is_scalar($value) && trim((string) $value) !== '') {
                    $attributes[$slug] = trim((string) $value);
                }
            }

            if ($attributes !== []) {
                $query['attributes'] = $attributes;
            }
        }

        ksort($query);

        return $query;
    }

    /**
     * @param  callable(): array<string, mixed>  $load
     * @return array<string, mixed>
     */
    private static function cached(string $key, string $tag, int $ttl, callable $load): array
    {
        return ApiCache::remember($key, $tag, $ttl, $load);
    }
}
