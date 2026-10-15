<?php

declare(strict_types=1);

namespace Storefront\Support;

/**
 * A short-lived, tagged copy of what the ERP said, in MySQL.
 *
 * It exists to spare the ERP's rate limit on pages many people open, not to be
 * believed at checkout: a quote and an order are never cached (contract §8.7).
 * A webhook drops a tag, and a TTL bounds how stale anything can be if a
 * webhook is lost.
 */
final class ApiCache
{
    /**
     * @template T of array<array-key, mixed>
     *
     * @param  callable(): T  $load
     * @return T
     */
    public static function remember(string $key, string $tag, int $ttlSeconds, callable $load): array
    {
        $key = substr($key, 0, 190);

        $row = Db::one('SELECT payload FROM api_cache WHERE cache_key = ? AND expires_at > ?', [$key, Db::now()]);

        if ($row !== null) {
            $decoded = json_decode((string) $row['payload'], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $value = $load();

        Db::exec(
            'INSERT INTO api_cache (cache_key, tag, payload, stored_at, expires_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE tag = VALUES(tag), payload = VALUES(payload),
                 stored_at = VALUES(stored_at), expires_at = VALUES(expires_at)',
            [
                $key,
                substr($tag, 0, 64),
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                Db::now(),
                gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
            ],
        );

        return $value;
    }

    public static function forgetTag(string $tag): int
    {
        return Db::exec('DELETE FROM api_cache WHERE tag = ?', [$tag]);
    }

    public static function forgetKey(string $key): int
    {
        return Db::exec('DELETE FROM api_cache WHERE cache_key = ?', [substr($key, 0, 190)]);
    }

    public static function flush(): int
    {
        return Db::exec('DELETE FROM api_cache');
    }

    public static function prune(): int
    {
        return Db::exec('DELETE FROM api_cache WHERE expires_at < ?', [Db::now()]);
    }
}
