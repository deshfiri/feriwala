<?php

namespace App\Support\Idempotency;

use App\Support\Concurrency\DistributedLock;
use App\Support\Idempotency\Exceptions\IdempotencyKeyReused;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Makes an externally triggered operation safe to retry.
 *
 * Gateway callbacks, storefront order submissions, and webhook deliveries all
 * arrive more than once — networks time out after the work succeeded, providers
 * retry, users double-click. Without this, a retried payment callback credits a
 * wallet twice and the ledger is wrong in a way that is expensive to unpick
 * (requirements.txt §17.3, §26.4, §36.1).
 *
 * Semantics match the frozen storefront contract (§4.7):
 *
 *   - first use of a key            → the work runs, its result is stored
 *   - replay, same key + same body  → the stored result, without re-running
 *   - replay, same key + different body → refused
 *
 * The body fingerprint is what makes the third case detectable. Without it, a
 * client that reused a key for genuinely different content would silently receive
 * the wrong response.
 *
 * This cache-backed implementation covers the 24-hour replay window. Durable
 * records for financial reconciliation are written alongside it by the payment
 * and order modules, which own their own tables.
 */
class IdempotencyService
{
    /**
     * The contract's replay window: 24 hours.
     */
    public const DEFAULT_TTL = 86400;

    /**
     * How long a single operation may hold its key's lock.
     */
    protected const LOCK_TTL = 30;

    /**
     * How long a concurrent duplicate waits for the first to finish.
     */
    protected const LOCK_WAIT = 10;

    public function __construct(
        protected CacheFactory $cache,
        protected DistributedLock $lock,
    ) {}

    /**
     * Execute the callback at most once for the given key.
     *
     * @template TReturn
     *
     * @param  array<array-key, mixed>|string|null  $payload  request body, fingerprinted to detect key reuse
     * @param  Closure(): TReturn  $callback
     * @return IdempotentOutcome<TReturn>
     *
     * @throws IdempotencyKeyReused
     */
    public function execute(
        string $key,
        array|string|null $payload,
        Closure $callback,
        int $ttlSeconds = self::DEFAULT_TTL,
    ): IdempotentOutcome {
        $fingerprint = $this->fingerprint($payload);

        // Fast path: already recorded, no need to take a lock at all.
        if ($record = $this->find($key)) {
            return $this->replay($key, $record, $fingerprint);
        }

        // Two identical requests can arrive at once. The lock makes the second
        // wait for the first rather than both executing.
        return $this->lock->run(
            key: 'idempotency:'.$key,
            callback: function () use ($key, $fingerprint, $callback, $ttlSeconds) {
                if ($record = $this->find($key)) {
                    return $this->replay($key, $record, $fingerprint);
                }

                $value = $callback();

                $this->cache->store()->put($this->cacheKey($key), [
                    'fingerprint' => $fingerprint,
                    'value' => $value,
                ], $ttlSeconds);

                return new IdempotentOutcome($value, replayed: false);
            },
            ttlSeconds: self::LOCK_TTL,
            waitSeconds: self::LOCK_WAIT,
        );
    }

    /**
     * Whether this key has already been used.
     */
    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * Forget a key. Intended for tests and for administrative correction of a
     * poisoned key — never as part of normal request handling.
     */
    public function forget(string $key): void
    {
        $this->cache->store()->forget($this->cacheKey($key));
    }

    /**
     * @param  array{fingerprint: string, value: mixed}  $record
     * @return IdempotentOutcome<mixed>
     *
     * @throws IdempotencyKeyReused
     */
    protected function replay(string $key, array $record, string $fingerprint): IdempotentOutcome
    {
        if (! hash_equals($record['fingerprint'], $fingerprint)) {
            throw IdempotencyKeyReused::forKey($key);
        }

        return new IdempotentOutcome($record['value'], replayed: true);
    }

    /**
     * @return array{fingerprint: string, value: mixed}|null
     */
    protected function find(string $key): ?array
    {
        $record = $this->cache->store()->get($this->cacheKey($key));

        if (! is_array($record) || ! array_key_exists('fingerprint', $record)) {
            return null;
        }

        return [
            'fingerprint' => (string) $record['fingerprint'],
            'value' => $record['value'] ?? null,
        ];
    }

    /**
     * A stable hash of the request body.
     *
     * Arrays are sorted recursively first, so a client that serialises its JSON
     * keys in a different order on retry is still recognised as sending the same
     * request rather than being rejected for key reuse.
     *
     * @param  array<array-key, mixed>|string|null  $payload
     */
    protected function fingerprint(array|string|null $payload): string
    {
        if ($payload === null) {
            return hash('sha256', '');
        }

        if (is_string($payload)) {
            return hash('sha256', $payload);
        }

        $normalised = $this->sortRecursive($payload);

        return hash('sha256', (string) json_encode($normalised));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected function sortRecursive(array $value): array
    {
        foreach ($value as $index => $item) {
            if (is_array($item)) {
                $value[$index] = $this->sortRecursive($item);
            }
        }

        // Only associative arrays are reordered. A list's order is meaningful —
        // reordering order line items would make two different orders look alike.
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    protected function cacheKey(string $key): string
    {
        return 'idempotency:'.hash('sha256', $key);
    }
}
