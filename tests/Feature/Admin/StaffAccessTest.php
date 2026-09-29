<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The platform staff directory, and assigning one of the twenty-one
 * PlatformRole cases (commit-order item 6, the write side of Roles &
 * Permissions). Every safety guard is exercised at the unit level in
 * AssignPlatformRoleTest; these tests cover the HTTP boundary around it --
 * the permission gate, the password confirmation, and the direct-URL
 * authorization a role-assignment screen has to hold to.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actor = testPlatformStaff(PlatformRole::SystemAdministrator);
});

it('redirects a guest to the login page', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    $this->get(route('admin.staff-access.index'))->assertRedirect(route('login'));
    $this->get(route('admin.staff-access.show', $subject->public_id))->assertRedirect(route('login'));
});

it('is closed to staff without access.view', function () {
    $reader = testPlatformStaff(PlatformRole::SmsManager);
    $subject = testPlatformStaff(PlatformRole::SeoManager);

    $this->actingAs($reader)->get(route('admin.staff-access.index'))->assertForbidden();
    $this->actingAs($reader)->get(route('admin.staff-access.show', $subject->public_id))->assertForbidden();
});

it('lists only platform staff, never a business account\'s own staff', function () {
    testPlatformStaff(PlatformRole::SmsManager);

    $account = testBusinessAccount();
    $businessStaff = User::factory()->create();
    $account->memberships()->create(['user_id' => $businessStaff->id, 'role' => AccountRole::Staff]);

    $this->actingAs($this->actor)
        ->get(route('admin.staff-access.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($businessStaff) {
            $emails = collect($page->toArray()['props']['staff']['data'])->pluck('email');

            expect($emails)->not->toContain($businessStaff->email);
        });
});

it('filters the directory by a search term', function () {
    testPlatformStaff(PlatformRole::SmsManager)->update(['name' => 'Karim Ahmed']);
    testPlatformStaff(PlatformRole::SeoManager)->update(['name' => 'Fatema Begum']);

    $this->actingAs($this->actor)
        ->get(route('admin.staff-access.index', ['search' => 'Karim']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('staff.data', 1)
            ->where('staff.data.0.name', 'Karim Ahmed'));
});

it('shows a staff member\'s effective permissions and role options', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($this->actor)
        ->get(route('admin.staff-access.show', $subject->public_id))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('admin/staff-access/show')
                ->where('staffMember.role_label', 'SMS Manager')
                ->where('can.manage', true);

            $groups = collect($page->toArray()['props']['permissionGroups']);
            $roles = collect($page->toArray()['props']['roles']);

            expect($groups->pluck('module'))->toContain('SMS')
                ->and($roles->pluck('key'))->not->toContain('super_admin');
        });
});

it('offers Super Admin as an option only to an actor who already holds it', function () {
    $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($superAdmin)
        ->get(route('admin.staff-access.show', $subject->public_id))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $roles = collect($page->toArray()['props']['roles']);

            expect($roles->pluck('key'))->toContain('super_admin');
        });
});

it('404s for a business account owner rather than treating them as staff', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->actor)
        ->get(route('admin.staff-access.show', $account->owner->public_id))
        ->assertNotFound();
});

describe('assigning a role', function () {
    it('requires a recently confirmed password', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->put(route('admin.staff-access.role.update', $subject->public_id), [
                'role' => PlatformRole::SeoManager->value,
                'reason' => 'Reassigned to the SEO team.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
    });

    it('assigns the role once the password is confirmed, and records why', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff-access.role.update', $subject->public_id), [
                'role' => PlatformRole::SeoManager->value,
                'reason' => 'Reassigned to the SEO team.',
            ])
            ->assertRedirect();

        expect($subject->fresh()->hasRole(PlatformRole::SeoManager->value))->toBeTrue()
            ->and(AuditLog::query()
                ->where('action', 'access.role_assigned')
                ->where('actor_id', $this->actor->id)
                ->where('reason', 'Reassigned to the SEO team.')
                ->exists())->toBeTrue();
    });

    /*
     * AssignPlatformRole's own "last active Super Admin" guard is not
     * reachable through this controller at all, and that is by design: the
     * policy already refuses anyone but a Super Admin acting on a Super
     * Admin subject, and a Super Admin actor is themselves always "another"
     * active Super Admin relative to any other subject they act on -- the
     * guard exists for callers that do not sit behind that same policy
     * (a console command, say). It is exercised directly at the action
     * level in AssignPlatformRoleTest.
     */
    it('lets a Super Admin demote another Super Admin while one still remains', function () {
        $rootAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        $otherAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($rootAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff-access.role.update', $otherAdmin->public_id), [
                'role' => PlatformRole::SeoManager->value,
                'reason' => 'One Super Admin is enough for now.',
            ])
            ->assertRedirect();

        expect($otherAdmin->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeFalse();
    });

    it('refuses an actor changing their own role by direct URL', function () {
        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff-access.role.update', $this->actor->public_id), [
                'role' => PlatformRole::SeoManager->value,
                'reason' => 'Trying it on myself.',
            ])
            ->assertForbidden();

        expect($this->actor->fresh()->hasRole(PlatformRole::SystemAdministrator->value))->toBeTrue();
    });

    it('refuses anyone but a Super Admin acting on a Super Admin, by direct URL', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff-access.role.update', $superAdmin->public_id), [
                'role' => PlatformRole::SeoManager->value,
                'reason' => 'Trying it without being a Super Admin.',
            ])
            ->assertForbidden();
    });

    it('refuses a change from somebody without access.edit, by direct URL', function () {
        $reader = testPlatformStaff(PlatformRole::SeoManager);
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($reader)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff-access.role.update', $subject->public_id), [
                'role' => PlatformRole::KycManager->value,
                'reason' => 'Should be refused.',
            ])
            ->assertForbidden();

        expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
    });

    it('rejects a role value that is not one of the twenty-one cases', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff-access.role.update', $subject->public_id), [
                'role' => 'made-up-role',
                'reason' => 'Should be refused.',
            ])
            ->assertSessionHasErrors('role');
    });
});
