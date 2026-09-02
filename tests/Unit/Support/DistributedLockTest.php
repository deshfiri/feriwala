<?php

use App\Support\Concurrency\DistributedLock;
use App\Support\Concurrency\Exceptions\LockTimeout;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Store;

/**
 * A cache factory over a single store, so these tests exercise the real lock
 * mechanics rather than a mock of them.
 */
function factoryFor(Store $store): CacheFactory
{
    return new class(new Repository($store)) implements CacheFactory
    {
        public function __construct(private Repository $repository) {}

        public function store($name = null): Repository
        {
            return $this->repository;
        }
    };
}

function lockOverArrayStore(): array
{
    $store = new ArrayStore(true);

    return [new DistributedLock(factoryFor($store)), $store];
}

it('runs the callback while holding the lock and returns its value', function () {
    [$lock] = lockOverArrayStore();

    expect($lock->run('wallet:1', fn () => 'credited'))->toBe('credited');
});

it('releases the lock once the callback finishes', function () {
    [$lock] = lockOverArrayStore();

    $lock->run('wallet:1', fn () => null);

    expect($lock->isHeld('wallet:1'))->toBeFalse();
});

it('releases the lock even when the callback throws', function () {
    [$lock] = lockOverArrayStore();

    // A failed wallet debit must not leave the wallet locked for everyone else.
    expect(fn () => $lock->run('wallet:1', function () {
        throw new RuntimeException('debit failed');
    }))->toThrow(RuntimeException::class);

    expect($lock->isHeld('wallet:1'))->toBeFalse();
});

it('refuses to run when another holder has the lock', function () {
    [$lock, $store] = lockOverArrayStore();

    // Simulate another process holding it.
    $store->lock('lock:wallet:1', 10)->acquire();

    expect(fn () => $lock->run('wallet:1', fn () => 'should not run', waitSeconds: 0))
        ->toThrow(LockTimeout::class);
});

it('names the contended key in the timeout message', function () {
    [$lock, $store] = lockOverArrayStore();

    $store->lock('lock:wallet:99', 10)->acquire();

    expect(fn () => $lock->run('wallet:99', fn () => null, waitSeconds: 0))
        ->toThrow(LockTimeout::class, 'wallet:99');
});

it('does not run the callback at all when the lock is unavailable', function () {
    [$lock, $store] = lockOverArrayStore();
    $ran = false;

    $store->lock('lock:wallet:1', 10)->acquire();

    try {
        $lock->run('wallet:1', function () use (&$ran) {
            $ran = true;
        }, waitSeconds: 0);
    } catch (LockTimeout) {
        // expected
    }

    expect($ran)->toBeFalse();
});

describe('attempt', function () {
    it('runs when the lock is free', function () {
        [$lock] = lockOverArrayStore();

        expect($lock->attempt('sync:products', fn () => 'synced'))->toBe('synced');
    });

    it('returns null instead of waiting when the lock is taken', function () {
        [$lock, $store] = lockOverArrayStore();

        $store->lock('lock:sync:products', 10)->acquire();

        // A sync already in flight is pointless to duplicate and harmless to skip.
        expect($lock->attempt('sync:products', fn () => 'synced'))->toBeNull();
    });

    it('releases the lock afterwards', function () {
        [$lock] = lockOverArrayStore();

        $lock->attempt('sync:products', fn () => null);

        expect($lock->isHeld('sync:products'))->toBeFalse();
    });

    it('releases the lock when the callback throws', function () {
        [$lock] = lockOverArrayStore();

        expect(fn () => $lock->attempt('sync:products', function () {
            throw new RuntimeException('sync failed');
        }))->toThrow(RuntimeException::class);

        expect($lock->isHeld('sync:products'))->toBeFalse();
    });
});

it('keeps separate keys independent', function () {
    [$lock, $store] = lockOverArrayStore();

    $store->lock('lock:wallet:1', 10)->acquire();

    expect($lock->attempt('wallet:2', fn () => 'ok'))->toBe('ok');
});

it('namespaces lock keys away from cache entries', function () {
    [$lock, $store] = lockOverArrayStore();

    $lock->attempt('wallet:1', fn () => null);

    // A cache entry named the same thing must not be mistaken for the lock.
    expect($store->get('wallet:1'))->toBeNull();
});

it('refuses a cache store that cannot provide locks', function () {
    // A store without locking would leave financial operations unguarded while
    // appearing to work, so the capability is asserted rather than assumed.
    $store = new class implements Store
    {
        public function get($key) {}

        public function many(array $keys): array
        {
            return [];
        }

        public function put($key, $value, $seconds): bool
        {
            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            return true;
        }

        public function increment($key, $value = 1): int
        {
            return 0;
        }

        public function decrement($key, $value = 1): int
        {
            return 0;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            return true;
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };

    $lock = new DistributedLock(factoryFor($store));

    expect(fn () => $lock->run('wallet:1', fn () => null))
        ->toThrow(RuntimeException::class, 'cannot provide locks');
});
