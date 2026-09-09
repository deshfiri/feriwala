<?php

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PlatformRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

/*
 * A second factor before administration (P1-18, §36, §32.2).
 *
 * A password is one secret, and the roles this guards can move money, read
 * personal documents, restore backups and change what everyone else may do.
 */

/**
 * Platform staff who have **not** enrolled.
 *
 * `testPlatformStaff()` enrols the roles that require it, because otherwise
 * every administration test becomes a test of this redirect. These tests are
 * about the redirect, so they build the un-enrolled case themselves.
 */
function twoFactorTestStaff(PlatformRole $role): User
{
    $user = User::factory()->staff()->create();
    $user->assignRole($role->value);

    return $user;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('which roles need it', function () {
    it('is derived from the sensitive actions rather than a second list', function () {
        /*
         * §32.2 already names the actions that need more than a permission
         * behind them. A hand-kept list of roles beside it is one that drifts,
         * and it drifts silently — a role gaining `adjust_wallet` would keep
         * whatever answer somebody typed a year earlier.
         */
        foreach (PlatformRole::cases() as $role) {
            $sensitive = collect($role->permissions())
                ->map(fn (string $permission) => PermissionAction::tryFrom(
                    (string) str($permission)->after('.'),
                ))
                ->contains(fn (?PermissionAction $action) => $action?->isSensitive() ?? false);

            expect($role->requiresTwoFactor())->toBe(
                $sensitive || $role->grantsEverything(),
                sprintf('%s disagrees with its own permissions', $role->value),
            );
        }
    });

    it('catches the roles that can reach money, documents and backups', function () {
        expect(PlatformRole::SuperAdmin->requiresTwoFactor())->toBeTrue()
            ->and(PlatformRole::WalletManager->requiresTwoFactor())->toBeTrue()
            ->and(PlatformRole::WithdrawalApprover->requiresTwoFactor())->toBeTrue()
            ->and(PlatformRole::KycManager->requiresTwoFactor())->toBeTrue()
            ->and(PlatformRole::BackupManager->requiresTwoFactor())->toBeTrue();
    });

    it('leaves a role that only reads reports outside it', function () {
        // The boundary is real rather than decorative: view and export are not
        // sensitive actions, so a Report Viewer is not a sensitive role.
        expect(PlatformRole::ReportViewer->requiresTwoFactor())->toBeFalse();
    });

    it('is not asked of a business owner', function () {
        // Platform roles, not account roles. A shop owner signing in to their
        // own ERP is not covered by §36's sensitive-role rule.
        $owner = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        expect($owner->requiresTwoFactorAuthentication())->toBeFalse();
    });
});

describe('the gate on administration', function () {
    it('sends somebody without it to set it up rather than refusing them', function () {
        /*
         * Somebody who has just been given a role has done nothing wrong and has
         * one step to take. A 403 would tell them they do not have access, which
         * is the wrong thing to believe about their own account.
         */
        $manager = twoFactorTestStaff(PlatformRole::WalletManager);

        $this->actingAs($manager)
            ->get(route('admin.kyc.index'))
            ->assertRedirect(route('security.edit'));
    });

    it('lets them through once it is on', function () {
        $manager = twoFactorTestStaff(PlatformRole::KycManager);
        $manager->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($manager->refresh())
            ->get(route('admin.kyc.index'))
            ->assertOk();
    });

    it('does not ask a role that does not need it', function () {
        $viewer = twoFactorTestStaff(PlatformRole::ReportViewer);

        // Refused by the policy on the screen, not bounced to enrolment: the
        // difference is what tells the two problems apart.
        $this->actingAs($viewer)
            ->get(route('admin.kyc.index'))
            ->assertForbidden();
    });

    it('counts an unconfirmed secret as not enabled', function () {
        /*
         * Fortify is configured with `confirm`, so a secret that was generated
         * and never verified means somebody started enrolling and stopped —
         * they cannot actually produce a code, and treating that as enabled
         * would be a gate that passes everyone who opened the page.
         */
        $manager = twoFactorTestStaff(PlatformRole::WalletManager);
        $manager->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($manager->refresh())
            ->get(route('admin.kyc.index'))
            ->assertRedirect(route('security.edit'));
    });
});

describe('the enrolment screen', function () {
    it('says why it is being asked for', function () {
        $manager = twoFactorTestStaff(PlatformRole::WalletManager);

        $this->actingAs($manager)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('twoFactorRequired', true)
                ->where('twoFactorEnabled', false),
            );
    });

    it('does not nag somebody it is not required of', function () {
        $owner = User::factory()
            ->withBusinessAccount(fn ($account) => $account->active())
            ->create();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security')
                ->where('twoFactorRequired', false),
            );
    });
});

describe('the configuration §6 depends on', function () {
    it('confirms a second factor before trusting it, and asks for the password first', function () {
        /*
         * Without `confirm`, enabling two-factor stores a secret the person may
         * never have successfully used — and then locks them out at the next
         * sign-in. Without `confirmPassword`, anybody at an unattended desk can
         * enrol their own authenticator on somebody else's account.
         */
        expect(Features::canManageTwoFactorAuthentication())->toBeTrue()
            ->and(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'))->toBeTrue()
            ->and(Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'))->toBeTrue();
    });

    it('guards passkey enrolment with the password too', function () {
        // A passkey is a credential that signs in on its own. Adding one to
        // somebody else's account is taking the account.
        expect(Features::canManagePasskeys())->toBeTrue()
            ->and(Features::optionEnabled(Features::passkeys(), 'confirmPassword'))->toBeTrue();
    });
});
