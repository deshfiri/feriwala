<?php

use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\SetWebsiteSmsVerification;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;

/*
 * `website:sms-verification` — one command to force the cash-on-delivery SMS
 * code off for every storefront (and keep Partners from turning it back on), or
 * to require it of all of them.
 */
beforeEach(function () {
    app(SettingsRepository::class)->define(CheckoutVerification::POLICY, 'orders', SettingType::Boolean, false);

    $this->shops = collect(range(1, 3))->map(fn () => Website::factory()
        ->forAccount(websiteTestAccount())
        ->active()
        ->create(['sms_verification_enabled' => true]));
});

it('forces the code off for every storefront, including when the platform was requiring it', function () {
    app(SettingsRepository::class)->set(CheckoutVerification::POLICY, true);

    $this->artisan('website:sms-verification', ['state' => 'off', '--force' => true])
        ->expectsOutputToContain('3 switched off')
        ->assertSuccessful();

    $this->shops->each(function (Website $shop) {
        expect($shop->refresh()->sms_verification_enabled)->toBeFalse()
            ->and(app(CheckoutVerification::class)->requiredFor($shop))->toBeFalse();
    });

    expect(app(CheckoutVerification::class)->requiredByPlatform())->toBeFalse()
        ->and(app(CheckoutVerification::class)->disabledByPlatform())->toBeTrue();
});

it('keeps it off even for a shop whose own setting is on', function () {
    $this->artisan('website:sms-verification', ['state' => 'off', '--force' => true])->assertSuccessful();

    $late = Website::factory()->forAccount(websiteTestAccount())->active()->create(['sms_verification_enabled' => true]);

    expect(app(CheckoutVerification::class)->requiredFor($late))->toBeFalse()
        ->and(app(CheckoutVerification::class)->lockedFor($late))->toBeTrue();
});

it('does not let a Partner turn it back on', function () {
    $this->artisan('website:sms-verification', ['state' => 'off', '--force' => true])->assertSuccessful();

    $shop = $this->shops->first();

    expect(fn () => app(SetWebsiteSmsVerification::class)->handle($shop, $shop->businessAccount->owner, true))
        ->toThrow(WebsiteRefused::class);

    expect($shop->refresh()->sms_verification_enabled)->toBeFalse();
});

it('requires the code of every storefront when turned on, lifts the kill switch, and leaves each shop\'s own setting alone', function () {
    $this->artisan('website:sms-verification', ['state' => 'off', '--force' => true])->assertSuccessful();

    $this->artisan('website:sms-verification', ['state' => 'on', '--force' => true])->assertSuccessful();

    expect(app(CheckoutVerification::class)->requiredByPlatform())->toBeTrue()
        ->and(app(CheckoutVerification::class)->disabledByPlatform())->toBeFalse()
        ->and($this->shops->first()->refresh()->sms_verification_enabled)->toBeFalse()
        ->and(app(CheckoutVerification::class)->requiredFor($this->shops->first()))->toBeTrue();
});

it('asks first, and changes nothing when the answer is no', function () {
    $this->artisan('website:sms-verification', ['state' => 'off'])
        ->expectsConfirmation('Force the SMS confirmation code OFF on every storefront? Cash-on-delivery customers will no longer be asked for a code, and Partners cannot turn it back on.', 'no')
        ->assertSuccessful();

    expect(Website::query()->where('sms_verification_enabled', true)->count())->toBe(3)
        ->and(app(CheckoutVerification::class)->disabledByPlatform())->toBeFalse();
});

it('refuses a state it does not know', function () {
    $this->artisan('website:sms-verification', ['state' => 'maybe', '--force' => true])->assertFailed();
});

it('leaves one audit entry made by the system', function () {
    $this->artisan('website:sms-verification', ['state' => 'off', '--force' => true])->assertSuccessful();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'website.sms_verification_changed_everywhere',
        'actor_type' => 'system',
    ]);
});
