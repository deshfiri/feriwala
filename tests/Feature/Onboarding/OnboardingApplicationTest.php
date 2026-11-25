<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\AdvanceAccountPastVerification;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Kyc\Actions\ReviewKyc;
use App\Domain\Kyc\Data\KycDecision;
use App\Domain\Kyc\Enums\KycStatus;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use App\Notifications\Account\ApplicationCorrectionRequested;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Partner onboarding v2 (D39): pay first, then identity, personal details and
 * business setup, then an application a person reviews — and nothing activates
 * on its own.
 */

beforeEach(function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** An owner whose package is paid for and whose KYC documents are in. */
function onboardingApplicant(AccountStatus $status = AccountStatus::KycSubmitted): BusinessAccount
{
    $account = testBusinessAccount($status);
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

/**
 * @return array<string, mixed>
 */
function onboardingDetailsPayload(array $chain, array $overrides = []): array
{
    return [
        'legal_name' => 'Ayesha Rahman',
        'mobile_country' => 'BD',
        'mobile_number' => '01711111111',
        'country' => 'BD',
        'nationality' => 'BD',
        'date_of_birth' => '1992-04-12',
        'division_id' => $chain['division']->id,
        'district_id' => $chain['district']->id,
        'upazila_id' => $chain['upazila']->id,
        'union_id' => $chain['union']->id,
        'detailed_address' => 'House 4, Road 2, Block B',
        'postcode' => '1216',
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function onboardingBusinessPayload(array $overrides = []): array
{
    return [
        'name' => 'Ayesha Fashion House',
        'domain_name' => 'ayesha-fashion',
        'domain_extension' => '.shop',
        'use_personal_mobile' => true,
        'use_personal_email' => true,
        'has_address' => false,
        ...$overrides,
    ];
}

function onboardingCompleteApplication(BusinessAccount $account): void
{
    $account->forceFill([
        'personal_details_completed_at' => now(),
        'business_setup_completed_at' => now(),
        'preferred_domain_name' => 'ayesha-fashion',
        'preferred_domain_extension' => '.shop',
    ])->save();
}

describe('the order', function () {
    it('sends a newly verified account to package selection, not to KYC', function () {
        $account = testBusinessAccount(AccountStatus::EmailVerificationPending);
        $account->owner->forceFill(['email_verified_at' => now(), 'mobile_verified_at' => now()])->save();

        app(AdvanceAccountPastVerification::class)->handle($account, 'Verified.');

        expect($account->fresh()->status)->toBe(AccountStatus::PackageSelectionPending);
    });

    it('keeps the personal details step closed until the package is paid for', function () {
        $account = testBusinessAccount(AccountStatus::PackageSelectionPending);

        $this->actingAs($account->owner)
            ->get(route('onboarding.details.edit'))
            ->assertRedirect(route('onboarding.status'));
    });

    it('walks the stepper through details, business and submit once KYC is in', function () {
        $account = onboardingApplicant();

        $this->actingAs($account->owner)
            ->get(route('onboarding.status'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress.current_step', 'details')
                ->where('progress.action.url', route('onboarding.details.edit', absolute: false)));
    });
});

describe('personal details', function () {
    it('saves a Bangladeshi residence on the structured locations, and the facts from the ID', function () {
        $account = onboardingApplicant();
        $chain = addressTestLocationChain();

        $this->actingAs($account->owner)
            ->put(route('onboarding.details.update'), onboardingDetailsPayload($chain))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('onboarding.business.edit'));

        $owner = $account->owner->fresh();
        $address = SharedAddress::query()->where('owner_id', $account->id)->where('type', ClientAddressType::Residential->value)->sole();

        expect($owner->name)->toBe('Ayesha Rahman')
            ->and($owner->nationality)->toBe('BD')
            ->and($owner->date_of_birth->toDateString())->toBe('1992-04-12')
            ->and($address->district_id)->toBe($chain['district']->id)
            ->and($account->fresh()->personal_details_completed_at)->not->toBeNull();

        $audit = json_encode(AuditLog::query()->where('action', 'onboarding.personal_details_saved')->sole()->after, JSON_THROW_ON_ERROR);
        expect($audit)->not->toContain('Ayesha')->and($audit)->not->toContain('1992');
    });

    it('keeps a residence abroad as a typed address', function () {
        $account = onboardingApplicant();

        $this->actingAs($account->owner)
            ->put(route('onboarding.details.update'), [
                ...onboardingDetailsPayload(addressTestLocationChain(), ['country' => 'AE']),
                'line_1' => 'Flat 1203, Marina Tower',
                'city' => 'Dubai',
            ])
            ->assertSessionHasNoErrors();

        expect($account->owner->fresh()->country)->toBe('AE')
            ->and(SharedAddress::query()->where('owner_id', $account->id)->where('type', 'residential')->exists())->toBeFalse();
    });

    it('verifies a changed mobile number again before anything else', function () {
        $account = onboardingApplicant();

        $this->actingAs($account->owner)
            ->put(route('onboarding.details.update'), onboardingDetailsPayload(addressTestLocationChain(), [
                'mobile_country' => 'AE',
                'mobile_number' => '050 123 4567',
            ]))
            ->assertRedirect(route('verification.mobile'));

        $owner = $account->owner->fresh();

        expect($owner->mobile)->toBe('+971501234567')
            ->and($owner->mobile_verified_at)->toBeNull();
    });

    it('refuses someone under eighteen', function () {
        $account = onboardingApplicant();

        $this->actingAs($account->owner)
            ->put(route('onboarding.details.update'), onboardingDetailsPayload(addressTestLocationChain(), [
                'date_of_birth' => now()->subYears(17)->toDateString(),
            ]))
            ->assertSessionHasErrors('date_of_birth');
    });
});

describe('business setup', function () {
    it('saves the business name and a preferred domain, buying nothing', function () {
        $account = onboardingApplicant();
        $account->forceFill(['personal_details_completed_at' => now()])->save();

        $this->actingAs($account->owner)
            ->put(route('onboarding.business.update'), onboardingBusinessPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('onboarding.review.show'));

        $account->refresh();

        expect($account->name)->toBe('Ayesha Fashion House')
            ->and($account->preferredDomain())->toBe('ayesha-fashion.shop')
            ->and($account->business_mobile)->toBeNull()
            ->and($account->business_email)->toBeNull()
            ->and(DB::table('website_domains')->count())->toBe(0);
    });

    it('accepts only an extension the admin offers', function () {
        $account = onboardingApplicant();
        $account->forceFill(['personal_details_completed_at' => now()])->save();

        $this->actingAs($account->owner)
            ->put(route('onboarding.business.update'), onboardingBusinessPayload(['domain_extension' => '.xyz']))
            ->assertSessionHasErrors('domain_extension');
    });

    it('stores a separate business mobile in E.164', function () {
        $account = onboardingApplicant();
        $account->forceFill(['personal_details_completed_at' => now()])->save();

        $this->actingAs($account->owner)
            ->put(route('onboarding.business.update'), onboardingBusinessPayload([
                'use_personal_mobile' => false,
                'mobile_country' => 'BD',
                'mobile_number' => '01811112222',
            ]))
            ->assertSessionHasNoErrors();

        expect($account->fresh()->business_mobile)->toBe('+8801811112222');
    });
});

describe('submitting', function () {
    it('submits a complete application, which waits for KYC approval and a person', function () {
        $account = onboardingApplicant();
        onboardingCompleteApplication($account);

        $this->actingAs($account->owner)
            ->post(route('onboarding.review.submit'), ['confirm' => '1'])
            ->assertRedirect(route('onboarding.status'));

        expect($account->fresh()->application_submitted_at)->not->toBeNull()
            ->and($account->fresh()->status)->toBe(AccountStatus::KycSubmitted);

        $this->actingAs($account->owner)
            ->get(route('onboarding.status'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress.current_step', 'approval')
                ->where('progress.action', null)
                ->where('progress.pending.heading', __('onboarding.pending.heading'))
                ->where('progress.pending.invoice_url', route('subscription.invoices.index', absolute: false)));
    });

    it('reaches Pending Verification once KYC is approved, and is not activated', function () {
        $account = onboardingApplicant(AccountStatus::KycUnderReview);
        onboardingCompleteApplication($account);
        $account->forceFill(['application_submitted_at' => now()])->save();

        app(ReviewKyc::class)->handle(
            KycSubmission::query()->where('business_account_id', $account->id)->sole()->forceFill(['status' => KycStatus::UnderReview]),
            KycDecision::approve(reviewerId: testPlatformStaff(PlatformRole::Admin)->id, internalNote: 'Good.'),
        );

        expect($account->fresh()->status)->toBe(AccountStatus::ApprovalPending);
    });

    it('refuses an incomplete application', function () {
        $account = onboardingApplicant();

        $this->actingAs($account->owner)
            ->post(route('onboarding.review.submit'), ['confirm' => '1'])
            ->assertSessionHasErrors('onboarding');

        expect($account->fresh()->application_submitted_at)->toBeNull();
    });

    it('closes the steps once submitted', function () {
        $account = onboardingApplicant();
        onboardingCompleteApplication($account);
        $account->forceFill(['application_submitted_at' => now()])->save();

        $this->actingAs($account->owner)
            ->get(route('onboarding.business.edit'))
            ->assertRedirect(route('onboarding.status'));
    });
});

describe('a reviewer asking for a correction', function () {
    it('sends the business details back, tells the partner, and lets them resubmit', function () {
        $account = testAccountReadyForActivation();
        $reviewer = testPlatformStaff(PlatformRole::Admin);

        $this->actingAs($reviewer)
            ->post(route('admin.activations.request-correction', $account), [
                'sections' => ['domain'],
                'reason' => 'The preferred domain is already registered.',
                'feedback' => 'That domain is taken. Please choose another name.',
            ])
            ->assertSessionHasNoErrors();

        $account->refresh();

        expect($account->status)->toBe(AccountStatus::KycApproved)
            ->and($account->application_submitted_at)->toBeNull()
            ->and($account->business_setup_completed_at)->toBeNull()
            ->and($account->personal_details_completed_at)->not->toBeNull()
            ->and($account->correction_sections)->toBe(['domain']);

        Notification::assertSentTo($account->owner, ApplicationCorrectionRequested::class);

        $this->actingAs($account->owner)
            ->get(route('onboarding.status'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress.current_step', 'business')
                ->where('progress.feedback', 'That domain is taken. Please choose another name.'));
    });

    it('requires a reason, never filling one in', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->post(route('admin.activations.request-correction', $account), [
                'sections' => ['personal'],
                'feedback' => 'Your name does not match your ID.',
            ])
            ->assertSessionHasErrors('reason');
    });

    it('shows the reviewer the whole application', function () {
        $account = testAccountReadyForActivation();

        $this->actingAs(testPlatformStaff(PlatformRole::Admin))
            ->get(route('admin.activations.show', $account))
            ->assertInertia(fn (Assert $page) => $page
                ->where('application.domain.address', 'ready-shop.shop')
                ->where('can_request_correction', true));
    });
});

describe('registration', function () {
    it('takes a mobile from any country and keeps it in E.164', function () {
        $this->post(route('register.store'), [
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
            'mobile_country' => 'GB',
            'mobile' => '07911 123456',
            'country' => 'GB',
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasNoErrors();

        expect(User::query()->where('email', 'karim@example.com')->value('mobile'))->toBe('+447911123456');
    });

    it('refuses a number that is not a mobile of the chosen country', function () {
        $this->post(route('register.store'), [
            'name' => 'Karim Uddin',
            'email' => 'karim@example.com',
            'mobile_country' => 'BD',
            'mobile' => '12345',
            'password' => testStrongPassword(),
            'password_confirmation' => testStrongPassword(),
            'terms_accepted' => '1',
            'privacy_accepted' => '1',
        ])->assertSessionHasErrors('mobile');
    });
});
