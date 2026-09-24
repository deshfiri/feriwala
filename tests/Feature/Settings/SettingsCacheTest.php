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
    $this->settings->define('billing.registration_fee', 'billing', SettingType::Money, '1500.00');
    $this->settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'a-secret', isEncrypted: true);
});

it('caches nothing but plain values', function () {
    $this->settings->get('billing.registration_fee');

    /** @var array<string, mixed> $cached */
    $cached = CacheFacade::get(SettingsRepository::CACHE_KEY);

    $objects = collect($cached)->flatten(1)->filter(fn (mixed $value) => is_object($value));

    expect($objects)->toBeEmpty()
        ->and($cached['billing.registration_fee'])->toBe(['type' => 'money', 'value' => '1500.00']);
});

it('reads a money setting back as money in the request that only has the cache', function () {
    // Warm the cache, then read it the way a later request does: a repository
    // with no memory of its own.
    $this->settings->get('billing.registration_fee');

    $later = new SettingsRepository(app(Cache::class));
    $fee = $later->get('billing.registration_fee');

    expect($fee)->toBeInstanceOf(Money::class)
        ->and($fee->toDecimal())->toBe('1500.00');
});

/*
 * D26 regression: a Money setting is stored and cached as a decimal-string
 * Taka amount now, not a poisha-style integer. A caller that still treated
 * the cached value as minor units — dividing by 100 on the way in, or
 * multiplying by 100 on the way out — would silently turn a BDT 100.00 fee
 * into BDT 10,000.00 or BDT 1.00. These pin the exact figure through a
 * define → cache → cold-read round trip, for a whole amount and a fractional
 * one, so that regression cannot creep back in unnoticed.
 */
it('never inflates a stored money setting by a hundred through the cache', function () {
    CacheFacade::forget(SettingsRepository::CACHE_KEY);
    $settings = app(SettingsRepository::class);
    $settings->define('billing.gateway_charge_percent', 'billing', SettingType::Money, '100.00');

    $settings->get('billing.gateway_charge_percent');
    $later = new SettingsRepository(app(Cache::class));
    $fee = $later->get('billing.gateway_charge_percent');

    expect($fee)->toBeInstanceOf(Money::class)
        ->and($fee->toDecimal())->toBe('100.00')
        ->and($fee->toDecimal())->not->toBe('10000.00')
        ->and($fee->toDecimal())->not->toBe('1.00')
        ->and($fee->format())->toBe('৳100.00');
});

it('keeps a fractional money setting exact through the cache', function () {
    CacheFacade::forget(SettingsRepository::CACHE_KEY);
    $settings = app(SettingsRepository::class);
    $settings->define('billing.sample_fractional_fee', 'billing', SettingType::Money, '500.50');

    $settings->get('billing.sample_fractional_fee');
    $later = new SettingsRepository(app(Cache::class));
    $fee = $later->get('billing.sample_fractional_fee');

    expect($fee->toDecimal())->toBe('500.50')
        ->and($fee->format())->toBe('৳500.50');
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
