<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Actions\ConfigureMobileVerificationRequirement;
use App\Domain\Account\Actions\SendMobileVerificationCode;
use App\Domain\Account\Actions\SkipMobileVerificationIfNotRequired;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\MobileVerificationRequirement;
use App\Domain\Account\VerificationCodes;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Switching mobile verification off (§5.1): not just "no code is sent" --
 * the step itself is removed, so nobody registering while it is off is left
 * waiting on a code they have no way to receive.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function mobileRequirementOwner(AccountStatus $status = AccountStatus::Registered, bool $emailVerified = false): User
{
    $account = testBusinessAccount($status);

    $account->owner->forceFill([
        'mobile' => '+8801712345678',
        'mobile_verified_at' => null,
        'email_verified_at' => $emailVerified ? now() : null,
    ])->save();

    return $account->owner->fresh();
}

describe('the requirement itself', function () {
    it('is required until an administrator switches it off', function () {
        expect(app(MobileVerificationRequirement::class)->isRequired())->toBeTrue();
    });

    it('stores the change and audits it', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);

        app(ConfigureMobileVerificationRequirement::class)->handle($admin, false);

        expect(app(MobileVerificationRequirement::class)->isRequired())->toBeFalse();

        $entry = AuditLog::query()
            ->where('action', 'account.mobile_verification_requirement_changed')
            ->firstOrFail();

        expect($entry->before['required'])->toBeTrue()
            ->and($entry->after['required'])->toBeFalse()
            ->and($entry->actor_id)->toBe($admin->id);
    });
});

describe('advancing an account the requirement is off for', function () {
    it('does nothing while the requirement is still on', function () {
        $user = mobileRequirementOwner();

        app(SkipMobileVerificationIfNotRequired::class)->handle($user->businessAccount);

        expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::Registered);
    });

    it('moves to email verification when email is not yet confirmed either', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);
        app(ConfigureMobileVerificationRequirement::class)->handle($admin, false);

        $user = mobileRequirementOwner(emailVerified: false);

        app(SkipMobileVerificationIfNotRequired::class)->handle($user->businessAccount);

        expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::EmailVerificationPending);
    });

    it('moves straight to KYC when email is already confirmed', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);
        app(ConfigureMobileVerificationRequirement::class)->handle($admin, false);

        $user = mobileRequirementOwner(emailVerified: true);

        app(SkipMobileVerificationIfNotRequired::class)->handle($user->businessAccount);

        expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::KycPending);
    });

    it('does not drag a further-along account backwards', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);
        app(ConfigureMobileVerificationRequirement::class)->handle($admin, false);

        $user = mobileRequirementOwner(AccountStatus::KycApproved, emailVerified: true);

        app(SkipMobileVerificationIfNotRequired::class)->handle($user->businessAccount);

        expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::KycApproved);
    });
});

describe('the mobile verification screen with the requirement off', function () {
    beforeEach(function () {
        $admin = testPlatformStaff(PlatformRole::Admin);
        app(ConfigureMobileVerificationRequirement::class)->handle($admin, false);
    });

    it('redirects away instead of showing the form', function () {
        $user = mobileRequirementOwner();

        $this->actingAs($user)
            ->get(route('verification.mobile'))
            ->assertRedirect(route('onboarding.status'));
    });

    it('advances the account on the way through, without sending anything', function () {
        $user = mobileRequirementOwner(emailVerified: true);

        $this->actingAs($user)->get(route('verification.mobile'));

        expect($user->businessAccount->fresh()->status)->toBe(AccountStatus::KycPending)
            ->and(app(VerificationCodes::class)->isPending(SendMobileVerificationCode::PURPOSE, (string) $user->mobile))
            ->toBeFalse();
    });

    it('redirects away from send and verify too, rather than requiring a code', function () {
        $user = mobileRequirementOwner();

        $this->actingAs($user)
            ->post(route('verification.mobile.send'))
            ->assertRedirect(route('onboarding.status'));

        $this->actingAs($user)
            ->post(route('verification.mobile.verify'), ['code' => '000000'])
            ->assertRedirect(route('onboarding.status'));
    });

    it('shows the onboarding stepper pointing at the next real step, not mobile verification', function () {
        $user = mobileRequirementOwner(emailVerified: false);

        $this->actingAs($user)
            ->get(route('onboarding.status'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress.action.url', route('verification.notice', absolute: false)),
            );
    });
});

describe('the settings screen', function () {
    it('saves the switch', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);

        $this->actingAs($admin)
            ->put(route('admin.account-verification-settings.update'), ['mobile_verification_required' => false])
            ->assertRedirect();

        expect(app(MobileVerificationRequirement::class)->isRequired())->toBeFalse();
    });

    it('shows the current state', function () {
        $admin = testPlatformStaff(PlatformRole::Admin);

        $this->actingAs($admin)
            ->get(route('admin.account-verification-settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/account-verification-settings')
                ->where('settings.mobile_verification_required', true)
                ->where('can.manage', true),
            );
    });

    it('is closed to a role without account.manage_settings', function () {
        $packageManager = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($packageManager)
            ->put(route('admin.account-verification-settings.update'), ['mobile_verification_required' => false])
            ->assertForbidden();

        expect(app(MobileVerificationRequirement::class)->isRequired())->toBeTrue();
    });
});
