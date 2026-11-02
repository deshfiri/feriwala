<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A short-lived, tagged copy of what the ERP said, in the database.
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
    public function remember(string $key, string $tag, int $ttlSeconds, callable $load): array
    {
        $key = substr($key, 0, 190);

        $payload = DB::table('api_cache')
            ->where('cache_key', $key)
            ->where('expires_at', '>', now()->utc()->toDateTimeString())
            ->value('payload');

        if ($payload !== null) {
            $decoded = json_decode((string) $payload, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $value = $load();

        DB::table('api_cache')->upsert([[
            'cache_key' => $key,
            'tag' => substr($tag, 0, 64),
            'payload' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'stored_at' => now()->utc()->toDateTimeString(),
            'expires_at' => now()->utc()->addSeconds($ttlSeconds)->toDateTimeString(),
        ]], ['cache_key'], ['tag', 'payload', 'stored_at', 'expires_at']);

        return $value;
    }

    public function forgetTag(string $tag): int
    {
        return DB::table('api_cache')->where('tag', $tag)->delete();
    }

    public function forgetKey(string $key): int
    {
        return DB::table('api_cache')->where('cache_key', substr($key, 0, 190))->delete();
    }

    public function flush(): int
    {
        return DB::table('api_cache')->delete();
    }

    public function prune(): int
    {
        return DB::table('api_cache')->where('expires_at', '<', now()->utc()->toDateTimeString())->delete();
    }
}
