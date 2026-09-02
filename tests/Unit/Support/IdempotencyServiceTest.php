<?php

use App\Support\Concurrency\DistributedLock;
use App\Support\Idempotency\Exceptions\IdempotencyKeyReused;
use App\Support\Idempotency\IdempotencyService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;

function idempotency(): IdempotencyService
{
    $container = new Container;
    $container['config'] = new Config([
        'cache' => [
            'default' => 'array',
            'stores' => ['array' => ['driver' => 'array']],
            'prefix' => 'test',
        ],
    ]);

    $manager = new CacheManager($container);
    $manager->extend('array', fn () => $manager->repository(new ArrayStore(true)));

    return new IdempotencyService($manager, new DistributedLock($manager));
}

it('runs the work the first time a key is seen', function () {
    $service = idempotency();
    $runs = 0;

    $outcome = $service->execute('key-1', ['amount' => 100], function () use (&$runs) {
        $runs++;

        return 'created';
    });

    expect($outcome->value)->toBe('created')
        ->and($outcome->replayed)->toBeFalse()
        ->and($outcome->executed())->toBeTrue()
        ->and($runs)->toBe(1);
});

it('replays the stored result instead of running twice', function () {
    $service = idempotency();
    $runs = 0;

    $work = function () use (&$runs) {
        $runs++;

        return 'order-'.$runs;
    };

    $first = $service->execute('key-1', ['amount' => 100], $work);
    $second = $service->execute('key-1', ['amount' => 100], $work);

    expect($runs)->toBe(1)
        ->and($second->value)->toBe($first->value)
        ->and($second->replayed)->toBeTrue();
});

it('refuses a key replayed with a different body', function () {
    $service = idempotency();

    $service->execute('key-1', ['amount' => 100], fn () => 'first');

    expect(fn () => $service->execute('key-1', ['amount' => 999], fn () => 'second'))
        ->toThrow(IdempotencyKeyReused::class);
});

it('does not run the work when it refuses a reused key', function () {
    $service = idempotency();
    $runs = 0;

    $service->execute('key-1', ['amount' => 100], function () use (&$runs) {
        $runs++;

        return 'first';
    });

    try {
        $service->execute('key-1', ['amount' => 999], function () use (&$runs) {
            $runs++;

            return 'second';
        });
    } catch (IdempotencyKeyReused) {
        // expected
    }

    expect($runs)->toBe(1);
});

it('treats reordered object keys as the same request', function () {
    $service = idempotency();

    $service->execute('key-1', ['b' => 2, 'a' => 1], fn () => 'ok');

    $replay = $service->execute('key-1', ['a' => 1, 'b' => 2], fn () => 'should not run');

    expect($replay->replayed)->toBeTrue()
        ->and($replay->value)->toBe('ok');
});

it('treats reordered list items as different requests', function () {
    $service = idempotency();

    // Two order lines swapped is not the same order — list order is meaningful.
    $service->execute('key-1', ['items' => ['a', 'b']], fn () => 'ok');

    expect(fn () => $service->execute('key-1', ['items' => ['b', 'a']], fn () => 'other'))
        ->toThrow(IdempotencyKeyReused::class);
});

it('keeps separate keys independent', function () {
    $service = idempotency();
    $runs = 0;

    $work = function () use (&$runs) {
        return ++$runs;
    };

    expect($service->execute('key-1', ['a' => 1], $work)->value)->toBe(1)
        ->and($service->execute('key-2', ['a' => 1], $work)->value)->toBe(2);
});

it('handles a null body', function () {
    $service = idempotency();

    $service->execute('key-1', null, fn () => 'ok');

    expect($service->execute('key-1', null, fn () => 'again')->replayed)->toBeTrue();
});

it('fingerprints raw string bodies', function () {
    $service = idempotency();

    $service->execute('key-1', '{"a":1}', fn () => 'ok');

    expect($service->execute('key-1', '{"a":1}', fn () => 'again')->replayed)->toBeTrue()
        ->and(fn () => $service->execute('key-1', '{"a":2}', fn () => 'again'))
        ->toThrow(IdempotencyKeyReused::class);
});

it('reports and forgets keys', function () {
    $service = idempotency();

    expect($service->has('key-1'))->toBeFalse();

    $service->execute('key-1', ['a' => 1], fn () => 'ok');
    expect($service->has('key-1'))->toBeTrue();

    $service->forget('key-1');
    expect($service->has('key-1'))->toBeFalse();
});

it('stores a falsy result and still replays it', function () {
    $service = idempotency();
    $runs = 0;

    $work = function () use (&$runs) {
        $runs++;

        return null;
    };

    $service->execute('key-1', ['a' => 1], $work);
    $replay = $service->execute('key-1', ['a' => 1], $work);

    expect($runs)->toBe(1)
        ->and($replay->replayed)->toBeTrue()
        ->and($replay->value)->toBeNull();
});
