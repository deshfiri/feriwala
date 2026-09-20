<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;

/**
 * What the settings cache holds (§9, §40).
 *
 * The cache stores unserialize **no classes at all**, so that a leaked
 * application key cannot be turned into a gadget chain (config/cache.php). A
 * setting cached as an object therefore comes back as an unusable half-object
 * in the next request — a registration fee that is no longer a figure, and a
 * checkout that fails on reading it.
 *
 * So settings are cached as plain values and typed after they are read.
 */
beforeEach(function () {
    CacheFacade::forget(SettingsRepository::CACHE_KEY);

    $this->settings = app(SettingsRepository::class);
    $this->settings->define('billing.registration_fee', 'billing', SettingType::Money, 150000);
    $this->settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'a-secret', isEncrypted: true);
});

it('caches nothing but plain values', function () {
    $this->settings->get('billing.registration_fee');

    /** @var array<string, mixed> $cached */
    $cached = CacheFacade::get(SettingsRepository::CACHE_KEY);

    $objects = collect($cached)->flatten(1)->filter(fn (mixed $value) => is_object($value));

    expect($objects)->toBeEmpty()
        ->and($cached['billing.registration_fee'])->toBe(['type' => 'money', 'value' => '150000']);
});

it('reads a money setting back as money in the request that only has the cache', function () {
    // Warm the cache, then read it the way a later request does: a repository
    // with no memory of its own.
    $this->settings->get('billing.registration_fee');

    $later = new SettingsRepository(app(Cache::class));
    $fee = $later->get('billing.registration_fee');

    expect($fee)->toBeInstanceOf(Money::class)
        ->and($fee->minorUnits)->toBe(150000);
});

it('survives a cache that refuses to unserialize any class at all', function () {
    $this->settings->get('billing.registration_fee');

    /** @var array<string, mixed> $cached */
    $cached = CacheFacade::get(SettingsRepository::CACHE_KEY);

    // Exactly what the hardened store does on the way out.
    $hardened = unserialize(serialize($cached), ['allowed_classes' => false]);

    expect($hardened)->toBe($cached);
});

it('still decrypts a credential before it is cached, and types it on the way out', function () {
    $password = $this->settings->get('payment.sslcommerz.sandbox.store_password');

    expect($password)->toBe('a-secret');

    $later = new SettingsRepository(app(Cache::class));

    expect($later->get('payment.sslcommerz.sandbox.store_password'))->toBe('a-secret');
});
