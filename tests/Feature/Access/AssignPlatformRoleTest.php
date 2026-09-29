<?php

use App\Domain\Access\Actions\AssignPlatformRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Assigning one of the twenty-one PlatformRole cases to a platform staff
 * member -- the backend half of commit-order item 5. No production code
 * called Spatie's assignRole/syncRoles before this; the whole "give staff a
 * role" flow did not exist.
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
        role: PlatformRole::SeoManager,
        reason: 'Reassigned to the SEO team.',
    );

    expect($subject->fresh()->hasRole(PlatformRole::SeoManager->value))->toBeTrue()
        ->and($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeFalse();
});

it('records why, and the roles held before and after', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    app(AssignPlatformRole::class)->handle(
        subject: $subject,
        actor: $this->actor,
        role: PlatformRole::SeoManager,
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
        role: PlatformRole::SeoManager,
        reason: '   ',
    ))->toThrow(InvalidArgumentException::class);

    expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
});

it('refuses to change the role of the person making the change', function () {
    expect(fn () => app(AssignPlatformRole::class)->handle(
        subject: $this->actor,
        actor: $this->actor,
        role: PlatformRole::SeoManager,
        reason: 'Trying it on myself.',
    ))->toThrow(InvalidArgumentException::class);

    expect($this->actor->fresh()->hasRole(PlatformRole::SystemAdministrator->value))->toBeTrue();
});

it('refuses to hand a platform role to a business account member', function () {
    $account = testBusinessAccount();

    expect(fn () => app(AssignPlatformRole::class)->handle(
        subject: $account->owner,
        actor: $this->actor,
        role: PlatformRole::SeoManager,
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});

describe('the last Super Admin', function () {
    it('cannot be demoted when no other active Super Admin exists', function () {
        $lastAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        expect(fn () => app(AssignPlatformRole::class)->handle(
            subject: $lastAdmin,
            actor: $this->actor,
            role: PlatformRole::SeoManager,
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
            role: PlatformRole::SeoManager,
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
            role: PlatformRole::SeoManager,
            reason: 'The other Super Admin is locked out.',
        ))->toThrow(InvalidArgumentException::class);
    });

    it('never blocks promoting someone new to Super Admin', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        app(AssignPlatformRole::class)->handle(
            subject: $subject,
            actor: $this->actor,
            role: PlatformRole::SuperAdmin,
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

    it('refuses an actor changing their own role, whatever they hold', function () {
        expect($this->actor->can('assignRole', $this->actor))->toBeFalse();
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
        role: PlatformRole::SeoManager,
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});
