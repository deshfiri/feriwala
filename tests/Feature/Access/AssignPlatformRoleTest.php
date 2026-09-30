<?php

use App\Domain\Access\Actions\AssignPlatformRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Assigning one or more PlatformRole cases to a platform staff member --
 * the backend half of Platform Staff management. No production code called
 * Spatie's assignRole/syncRoles before this; the whole "give staff a role"
 * flow did not exist.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actor = testPlatformStaff(PlatformRole::SystemAdministrator);
});

it('assigns a new role, revoking the one held before', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    app(AssignPlatformRole::class)->handle(
        subject: $subject,
        actor: $this->actor,
        roleNames: [PlatformRole::SeoManager->value],
        reason: 'Reassigned to the SEO team.',
    );

    expect($subject->fresh()->hasRole(PlatformRole::SeoManager->value))->toBeTrue()
        ->and($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeFalse();
});

it('assigns more than one role at once', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    app(AssignPlatformRole::class)->handle(
        subject: $subject,
        actor: $this->actor,
        roleNames: [PlatformRole::SeoManager->value, PlatformRole::ReportViewer->value],
        reason: 'Taking on a second area.',
    );

    expect($subject->fresh()->hasRole(PlatformRole::SeoManager->value))->toBeTrue()
        ->and($subject->fresh()->hasRole(PlatformRole::ReportViewer->value))->toBeTrue()
        ->and($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeFalse();
});

it('records why, and the roles held before and after', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    app(AssignPlatformRole::class)->handle(
        subject: $subject,
        actor: $this->actor,
        roleNames: [PlatformRole::SeoManager->value],
        reason: 'Reassigned to the SEO team.',
    );

    $entry = AuditLog::query()->where('action', 'access.role_assigned')->firstOrFail();

    expect($entry->actor_id)->toBe($this->actor->id)
        ->and($entry->auditable_id)->toBe($subject->id)
        ->and($entry->reason)->toBe('Reassigned to the SEO team.')
        ->and($entry->before['roles'])->toBe([PlatformRole::SmsManager->value])
        ->and($entry->after['roles'])->toBe([PlatformRole::SeoManager->value])
        ->and($entry->is_sensitive)->toBeTrue();
});

it('refuses without a recorded reason', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    expect(fn () => app(AssignPlatformRole::class)->handle(
        subject: $subject,
        actor: $this->actor,
        roleNames: [PlatformRole::SeoManager->value],
        reason: '   ',
    ))->toThrow(InvalidArgumentException::class);

    expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
});

it('refuses an empty role set', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    expect(fn () => app(AssignPlatformRole::class)->handle(
        subject: $subject,
        actor: $this->actor,
        roleNames: [],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);

    expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
});

it('refuses to hand a platform role to a business account member', function () {
    $account = testBusinessAccount();

    expect(fn () => app(AssignPlatformRole::class)->handle(
        subject: $account->owner,
        actor: $this->actor,
        roleNames: [PlatformRole::SeoManager->value],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});

describe('self-change', function () {
    it('lets an actor add a role they already have full authority over, to themselves', function () {
        app(AssignPlatformRole::class)->handle(
            subject: $this->actor,
            actor: $this->actor,
            roleNames: [PlatformRole::SystemAdministrator->value, PlatformRole::ReportViewer->value],
            reason: 'Taking on reporting too.',
        );

        expect($this->actor->fresh()->hasRole(PlatformRole::ReportViewer->value))->toBeTrue();
    });

    it('still refuses self-demotion when it would remove the last active Super Admin', function () {
        $lastAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect(fn () => app(AssignPlatformRole::class)->handle(
            subject: $lastAdmin,
            actor: $lastAdmin,
            roleNames: [PlatformRole::SeoManager->value],
            reason: 'Demoting myself.',
        ))->toThrow(InvalidArgumentException::class);

        expect($lastAdmin->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeTrue();
    });
});

describe('assigning a fixed role never needs the actor\'s own permission set', function () {
    it('lets an actor holding only access.edit grant a specialist role it does not itself hold', function () {
        // SystemAdministrator holds access.* but nothing under Module::Wallet
        // at all -- assigning WalletManager to someone else is precisely
        // what a centralized role-assignment screen is for, and must not
        // require the assigning admin to personally hold every permission
        // the role bundles. Only Super Admin itself is held to that
        // standard (see the "granting Super Admin" tests below).
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        app(AssignPlatformRole::class)->handle(
            subject: $subject,
            actor: $this->actor,
            roleNames: [PlatformRole::WalletManager->value],
            reason: 'Moving to the wallet team.',
        );

        expect($subject->fresh()->hasRole(PlatformRole::WalletManager->value))->toBeTrue();
    });
});

describe('granting Super Admin', function () {
    it('refuses an actor who does not already hold Super Admin', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        expect(fn () => app(AssignPlatformRole::class)->handle(
            subject: $subject,
            actor: $this->actor,
            roleNames: [PlatformRole::SuperAdmin->value],
            reason: 'Trying to self-escalate by proxy.',
        ))->toThrow(InvalidArgumentException::class);

        expect($subject->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeFalse();
    });
});

describe('the last Super Admin', function () {
    it('cannot be demoted when no other active Super Admin exists', function () {
        $lastAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect(fn () => app(AssignPlatformRole::class)->handle(
            subject: $lastAdmin,
            actor: $this->actor,
            roleNames: [PlatformRole::SeoManager->value],
            reason: 'Demoting the only Super Admin.',
        ))->toThrow(InvalidArgumentException::class);

        expect($lastAdmin->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeTrue();
    });

    it('can be demoted once another active Super Admin exists', function () {
        $firstAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        testPlatformStaff(PlatformRole::SuperAdmin);

        app(AssignPlatformRole::class)->handle(
            subject: $firstAdmin,
            actor: $this->actor,
            roleNames: [PlatformRole::SeoManager->value],
            reason: 'A second Super Admin now exists.',
        );

        expect($firstAdmin->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeFalse();
    });

    it('does not count a locked Super Admin as available', function () {
        $lastActive = testPlatformStaff(PlatformRole::SuperAdmin);
        testPlatformStaff(PlatformRole::SuperAdmin)->forceFill(['identity_status' => 'locked'])->save();

        expect(fn () => app(AssignPlatformRole::class)->handle(
            subject: $lastActive,
            actor: $this->actor,
            roleNames: [PlatformRole::SeoManager->value],
            reason: 'The other Super Admin is locked out.',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('never blocks a Super Admin promoting someone new to Super Admin', function () {
        $rootAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        app(AssignPlatformRole::class)->handle(
            subject: $subject,
            actor: $rootAdmin,
            roleNames: [PlatformRole::SuperAdmin->value],
            reason: 'Promoting a second Super Admin.',
        );

        expect($subject->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeTrue();
    });
});

describe('the assignRole policy', function () {
    it('lets an actor holding access.edit change a non-Super-Admin role', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        expect($this->actor->can('assignRole', $subject))->toBeTrue();
    });

    it('refuses an actor without access.edit', function () {
        $reader = testPlatformStaff(PlatformRole::SeoManager);
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        expect($reader->can('assignRole', $subject))->toBeFalse();
    });

    it('refuses anyone but a Super Admin acting on a Super Admin', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect($this->actor->can('assignRole', $superAdmin))->toBeFalse();
    });

    it('lets a Super Admin act on another Super Admin', function () {
        $rootAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        $otherAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect($rootAdmin->can('assignRole', $otherAdmin))->toBeTrue();
    });

    it('lets an actor holding access.edit change their own role, unlike lock()', function () {
        expect($this->actor->can('assignRole', $this->actor))->toBeTrue();
    });
});

/**
 * A defensive re-statement of D23's boundary: business staff (an
 * `AccountRole`) and platform roles (a `PlatformRole`) are different scopes,
 * and the fixture below proves the guard sees the difference.
 */
it('is unreachable for a business account member, whatever their own account role', function () {
    $account = testBusinessAccount();
    $staffUser = User::factory()->create();
    $account->memberships()->create(['user_id' => $staffUser->id, 'role' => AccountRole::Staff]);

    expect(fn () => app(AssignPlatformRole::class)->handle(
        subject: $staffUser,
        actor: $this->actor,
        roleNames: [PlatformRole::SeoManager->value],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});
