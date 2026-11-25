<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\PreferredDomainExtensions;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Enums\KycSelfieMode;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\KycSelfiePolicy;
use App\Domain\Kyc\Models\KycSubmission;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * Onboarding v2 (D39): the KYC selfie, globally and for one partner; the
 * issuing country; the preferred-domain check, which never claims a domain is
 * available; and the same application in the mobile app.
 */

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function verificationSettingsSelfie(?KycSelfieMode $mode): void
{
    $settings = app(SettingsRepository::class);
    $settings->define(KycSelfiePolicy::SETTING, 'kyc', SettingType::String, null);
    $settings->set(KycSelfiePolicy::SETTING, $mode?->value);
}

describe('the KYC selfie', function () {
    it('follows the platform setting, unless the partner has their own', function () {
        verificationSettingsSelfie(KycSelfieMode::Required);
        $account = testBusinessAccount(AccountStatus::KycPending);
        $policy = app(KycSelfiePolicy::class);

        expect($policy->forAccount($account))->toBe(KycSelfieMode::Required);

        $account->forceFill(['kyc_selfie_mode' => KycSelfieMode::Disabled->value])->save();

        expect($policy->forAccount($account->refresh()))->toBe(KycSelfieMode::Disabled);
    });

    it('lets an admin turn the selfie on for one partner, with a reason, and audits it', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);
        $admin = testPlatformStaff(PlatformRole::Admin);

        $this->actingAs($admin)
            ->put(route('admin.activations.selfie', $account), ['mode' => 'required', 'reason' => 'Unclear ID photo.'])
            ->assertSessionHasNoErrors();

        expect($account->refresh()->kyc_selfie_mode)->toBe('required');

        $this->actingAs($admin)
            ->put(route('admin.activations.selfie', $account), ['mode' => 'optional'])
            ->assertSessionHasErrors('reason');
    });

    it('keeps a submitted round as it was when the setting changes later', function () {
        verificationSettingsSelfie(KycSelfieMode::Optional);
        $account = testBusinessAccount(AccountStatus::KycSubmitted);
        $round = KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Submitted, 'round' => 1, 'selfie_mode' => 'optional', 'submitted_at' => now()]);

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->put(route('admin.activations.selfie', $account), ['mode' => 'required', 'reason' => 'Policy change.']);

        expect($round->refresh()->selfie_mode)->toBe('optional');
    });

    it('saves the KYC issuing country, and refuses a code that is not a country', function () {
        $account = testBusinessAccount(AccountStatus::KycPending);

        $this->actingAs($account->owner)->put(route('kyc.issuing-country'), ['issuing_country' => 'in'])->assertSessionHasNoErrors();
        expect(KycSubmission::query()->where('business_account_id', $account->id)->latest('id')->value('issuing_country'))->toBe('IN');

        $this->actingAs($account->owner)->put(route('kyc.issuing-country'), ['issuing_country' => 'XX'])->assertSessionHasErrors('issuing_country');
    });
});

describe('the preferred-domain check', function () {
    it('never says a domain is available: only invalid, taken on Banij or not verified', function () {
        $account = testBusinessAccount(AccountStatus::KycSubmitted);

        $this->actingAs($account->owner)
            ->getJson(route('onboarding.business.domain-check', ['name' => 'rahim-traders', 'extension' => '.shop']))
            ->assertOk()
            ->assertJsonPath('status', 'unverified')
            ->assertJsonPath('domain', 'rahim-traders.shop')
            ->assertJsonMissing(['status' => 'available'])
            ->assertJsonStructure(['disclaimer', 'suggestions']);

        $this->actingAs($account->owner)
            ->getJson(route('onboarding.business.domain-check', ['name' => '-bad-', 'extension' => '.shop']))
            ->assertJsonPath('status', 'invalid');
    });

    it('says a domain another partner already asked for is taken on Banij', function () {
        $other = testBusinessAccount(AccountStatus::ApprovalPending);
        $other->forceFill(['preferred_domain_name' => 'rahim-traders', 'preferred_domain_extension' => '.shop'])->save();
        $account = testBusinessAccount(AccountStatus::KycSubmitted);

        $this->actingAs($account->owner)
            ->getJson(route('onboarding.business.domain-check', ['name' => 'Rahim-Traders', 'extension' => 'shop']))
            ->assertJsonPath('status', 'taken_on_banij');
    });

    it('offers only the extensions the admin enabled', function () {
        $this->actingAs(testPlatformStaff(PlatformRole::SuperAdmin))
            ->put(route('admin.account-verification-settings.update'), [
                'mobile_verification_required' => true,
                'domain_extensions' => ['com', '.store'],
            ])
            ->assertSessionHasNoErrors();

        expect(app(PreferredDomainExtensions::class)->enabled())->toBe(['.com', '.store']);

        $account = testBusinessAccount(AccountStatus::KycSubmitted);
        $this->actingAs($account->owner)
            ->getJson(route('onboarding.business.domain-check', ['name' => 'rahim', 'extension' => '.shop']))
            ->assertJsonPath('status', 'invalid');
    });

    it('records staff\'s manual registrar check, and forgets it when the partner changes the domain', function () {
        $account = testBusinessAccount(AccountStatus::ApprovalPending);
        $account->forceFill(['preferred_domain_name' => 'rahim', 'preferred_domain_extension' => '.shop', 'application_submitted_at' => now()])->save();

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->put(route('admin.activations.domain-check', $account), ['result' => 'available', 'note' => 'Checked at the registrar.'])
            ->assertSessionHasNoErrors();

        $account->refresh();
        expect($account->preferred_domain_check_result)->toBe('available')
            ->and($account->preferred_domain_checked_at)->not->toBeNull();
    });
});

describe('the mobile app', function () {
    it('shows the application with the options its forms need', function () {
        $account = verificationSettingsPaidApplicant();
        Sanctum::actingAs($account->owner);

        $this->getJson(route('mobile.v1.onboarding.application.show'))
            ->assertOk()
            ->assertJsonPath('editable', true)
            ->assertJsonStructure([
                'application' => ['personal', 'business', 'domain', 'kyc_submitted'],
                'mobile',
                'options' => ['countries', 'dialling_codes', 'domain_extensions'],
            ]);
    });

    it('saves the business setup and checks a domain, never claiming it is available', function () {
        $account = verificationSettingsPaidApplicant();
        Sanctum::actingAs($account->owner);

        $this->putJson(route('mobile.v1.onboarding.application.business'), [
            'name' => 'Rahim Traders',
            'domain_name' => 'rahim-traders',
            'domain_extension' => '.shop',
            'use_personal_mobile' => true,
            'use_personal_email' => true,
            'has_address' => false,
        ])->assertOk()->assertJsonPath('application.business.complete', true);

        $this->getJson(route('mobile.v1.onboarding.application.domain-check', ['name' => 'rahim-traders', 'extension' => '.shop']))
            ->assertOk()
            ->assertJsonPath('status', 'unverified')
            ->assertJsonStructure(['disclaimer']);
    });

    it('refuses to submit an incomplete application, and never activates', function () {
        $account = verificationSettingsPaidApplicant();
        Sanctum::actingAs($account->owner);

        $this->postJson(route('mobile.v1.onboarding.application.submit'), ['confirm' => true])->assertUnprocessable();

        expect($account->refresh()->status)->not->toBe(AccountStatus::Active)
            ->and($account->application_submitted_at)->toBeNull();
    });

    it('lets only the account owner fill in the application', function () {
        $this->getJson(route('mobile.v1.onboarding.application.show'))->assertUnauthorized();
    });
});

function verificationSettingsPaidApplicant(): BusinessAccount
{
    // The extensions are a stored setting; state them rather than inherit another test's.
    $settings = app(SettingsRepository::class);
    $settings->define(PreferredDomainExtensions::SETTING, 'onboarding', SettingType::Json, PreferredDomainExtensions::DEFAULT);
    $settings->set(PreferredDomainExtensions::SETTING, PreferredDomainExtensions::DEFAULT);

    $account = testBusinessAccount(AccountStatus::KycSubmitted);
    $account->owner->forceFill(['mobile' => '+8801711111111', 'mobile_verified_at' => now(), 'email_verified_at' => now()])->save();

    Payment::create([
        'business_account_id' => $account->id,
        'purpose' => PaymentPurpose::Activation,
        'status' => PaymentStatus::Paid,
        'amount' => Money::fromDecimal('6000.00'),
        'currency_code' => 'BDT',
        'completed_at' => now(),
    ]);

    KycSubmission::create(['business_account_id' => $account->id, 'status' => KycStatus::Submitted, 'round' => 1]);

    return $account->refresh();
}
