<?php

namespace App\Support\Concurrency;

use App\Support\Concurrency\Exceptions\LockTimeout;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use RuntimeException;

/**
 * Named locks shared across every application server (requirements.txt §38, §40).
 *
 * Feriwala runs stateless app servers behind a load balancer, so a PHP-level or
 * per-process guard proves nothing — two requests racing to debit the same wallet
 * may be on different machines entirely. These locks live in Redis, on a database
 * kept separate from the cache so that clearing the cache cannot drop a lock that
 * is currently protecting a financial operation.
 *
 * A lock is a coordination tool, not a correctness guarantee on its own. Money and
 * stock still need a database transaction with row locking underneath (§36.1);
 * the distributed lock keeps contention down and makes duplicate work rare, while
 * the database keeps the invariant true.
 */
class DistributedLock
{
    public function __construct(
        protected CacheFactory $cache,
    ) {}

    /**
     * Run the callback while holding the lock, waiting up to $waitSeconds for it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws LockTimeout
     */
    public function run(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $lock = $this->lock($key, $ttlSeconds);

        try {
            return $lock->block($waitSeconds, $callback);
        } catch (LockTimeoutException) {
            throw LockTimeout::forKey($key, $waitSeconds);
        }
    }

    /**
     * Run the callback only if the lock is free right now; otherwise return null
     * without waiting.
     *
     * Use for work that is pointless to duplicate but harmless to skip — a
     * scheduled sync already running, a balance recheck already in flight.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn|null
     */
    public function attempt(string $key, Closure $callback, int $ttlSeconds = 10): mixed
    {
        $lock = $this->lock($key, $ttlSeconds);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether the named lock is currently held.
     */
    public function isHeld(string $key): bool
    {
        $lock = $this->lock($key, 1);

        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * Build a lock from the configured store.
     *
     * Not every cache store can provide locks, and a store that silently cannot
     * would leave financial operations unguarded while appearing to work. So the
     * capability is asserted rather than assumed.
     */
    protected function lock(string $key, int $ttlSeconds): Lock
    {
        $store = $this->cache->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(sprintf(
                'The configured cache store [%s] cannot provide locks. '
                .'Feriwala requires a lock-capable store (Redis) for financial and stock operations.',
                $store::class,
            ));
        }

        return $store->lock($this->qualify($key), $ttlSeconds);
    }

    /**
     * Namespace lock keys so they cannot collide with cache entries.
     */
    protected function qualify(string $key): string
    {
        return 'lock:'.$key;
    }
}
