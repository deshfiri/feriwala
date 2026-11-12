<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Models\Website;

/*
 * Cash on delivery is on for every storefront unless somebody switches it off:
 * nothing in the product turns it on, so an "off" default left every shop unable
 * to take an order from a customer who pays on delivery.
 */
it('is on by default for a website that says nothing about it', function () {
    expect(app(CodTerms::class)->enabledFor(Website::factory()->make(['payment_config' => null])))->toBeTrue();
});

it('can still be switched off for the whole platform', function () {
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    app(SettingsRepository::class)->set(CodTerms::ENABLED, false);

    expect(app(CodTerms::class)->enabledFor(Website::factory()->make(['payment_config' => null])))->toBeFalse();
});

it('can still be switched off, or kept on, by a shop\'s own setting', function () {
    expect(app(CodTerms::class)->enabledFor(Website::factory()->make(['payment_config' => ['cod' => ['enabled' => false]]])))->toBeFalse()
        ->and(app(CodTerms::class)->enabledFor(Website::factory()->make(['payment_config' => ['cod' => ['enabled' => true]]])))->toBeTrue();
});
