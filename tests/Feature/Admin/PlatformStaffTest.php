<?php

use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use App\Notifications\Access\PlatformStaffInvited;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Platform staff management: the directory, inviting a brand-new login,
 * role assignment, and sign-in status changes. Every safety guard is
 * exercised at the unit level in AssignPlatformRoleTest/
 * InvitePlatformStaffTest; these tests cover the HTTP boundary around it --
 * the permission gate, the password confirmation, and the direct-URL
 * authorization these screens have to hold to.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actor = testPlatformStaff(PlatformRole::SystemAdministrator);
});

it('redirects a guest to the login page', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    $this->get(route('admin.staff.index'))->assertRedirect(route('login'));
    $this->get(route('admin.staff.create'))->assertRedirect(route('login'));
    $this->get(route('admin.staff.show', $subject->public_id))->assertRedirect(route('login'));
});

it('is closed to staff without access.view', function () {
    $reader = testPlatformStaff(PlatformRole::SmsManager);
    $subject = testPlatformStaff(PlatformRole::SeoManager);

    $this->actingAs($reader)->get(route('admin.staff.index'))->assertForbidden();
    $this->actingAs($reader)->get(route('admin.staff.show', $subject->public_id))->assertForbidden();
});

it('lists only platform staff, never a business account\'s own staff', function () {
    testPlatformStaff(PlatformRole::SmsManager);

    $account = testBusinessAccount();
    $businessStaff = User::factory()->create();
    $account->memberships()->create(['user_id' => $businessStaff->id, 'role' => AccountRole::Staff]);

    $this->actingAs($this->actor)
        ->get(route('admin.staff.index'))
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
        ->get(route('admin.staff.index', ['search' => 'Karim']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('staff.data', 1)
            ->where('staff.data.0.name', 'Karim Ahmed'));
});

it('shows a staff member\'s effective permissions and role options', function () {
    $subject = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($this->actor)
        ->get(route('admin.staff.show', $subject->public_id))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('admin/staff/show')
                ->where('staffMember.role_label', 'SMS Manager')
                ->where('assignedRoles', ['sms_manager'])
                ->where('can.manageRoles', true);

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
        ->get(route('admin.staff.show', $subject->public_id))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $roles = collect($page->toArray()['props']['roles']);

            expect($roles->pluck('key'))->toContain('super_admin');
        });
});

it('404s for a business account owner rather than treating them as staff', function () {
    $account = testBusinessAccount();

    $this->actingAs($this->actor)
        ->get(route('admin.staff.show', $account->owner->public_id))
        ->assertNotFound();
});

describe('inviting a new staff member', function () {
    it('requires a recently confirmed password', function () {
        Notification::fake();

        $this->actingAs($this->actor)
            ->post(route('admin.staff.store'), [
                'name' => 'Karim Ahmed',
                'email' => 'karim@example.test',
                'roles' => [PlatformRole::SmsManager->value],
                'reason' => 'Joining the SMS team.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect(User::query()->where('email', 'karim@example.test')->exists())->toBeFalse();
    });

    it('creates the login and redirects to its page, once confirmed', function () {
        Notification::fake();

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.store'), [
                'name' => 'Karim Ahmed',
                'email' => 'karim2@example.test',
                'mobile' => '+8801700000009',
                'roles' => [PlatformRole::SmsManager->value],
                'reason' => 'Joining the SMS team.',
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'karim2@example.test')->firstOrFail();

        expect($user->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
        Notification::assertSentTo($user, PlatformStaffInvited::class);
    });

    it('is closed to staff without access.edit', function () {
        $reader = testPlatformStaff(PlatformRole::SeoManager);

        $this->actingAs($reader)->get(route('admin.staff.create'))->assertForbidden();

        $this->actingAs($reader)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.store'), [
                'name' => 'Karim Ahmed',
                'email' => 'karim3@example.test',
                'roles' => [PlatformRole::SmsManager->value],
                'reason' => 'Should be refused.',
            ])
            ->assertForbidden();
    });

    it('rejects a duplicate email', function () {
        Notification::fake();
        $existing = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.store'), [
                'name' => 'Karim Ahmed',
                'email' => $existing->email,
                'roles' => [PlatformRole::SmsManager->value],
                'reason' => 'Should be refused.',
            ])
            ->assertSessionHasErrors('email');
    });

    it('turns the action\'s own refusal into a form error', function () {
        Notification::fake();

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.store'), [
                'name' => 'Karim Ahmed',
                'email' => 'karim4@example.test',
                'roles' => [PlatformRole::SuperAdmin->value],
                'reason' => 'Should be refused.',
            ])
            ->assertSessionHasErrors('roles');

        expect(User::query()->where('email', 'karim4@example.test')->exists())->toBeFalse();
    });
});

describe('resending an invite', function () {
    it('sends another notification without changing the role', function () {
        Notification::fake();
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->post(route('admin.staff.resend-invite', $subject->public_id))
            ->assertRedirect();

        Notification::assertSentTo($subject, PlatformStaffInvited::class);
        expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
    });
});

describe('assigning roles', function () {
    it('requires a recently confirmed password', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->put(route('admin.staff.roles.update', $subject->public_id), [
                'roles' => [PlatformRole::SeoManager->value],
                'reason' => 'Reassigned to the SEO team.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
    });

    it('assigns the roles once the password is confirmed, and records why', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $subject->public_id), [
                'roles' => [PlatformRole::SeoManager->value, PlatformRole::ReportViewer->value],
                'reason' => 'Reassigned to the SEO team.',
            ])
            ->assertRedirect();

        expect($subject->fresh()->hasRole(PlatformRole::SeoManager->value))->toBeTrue()
            ->and($subject->fresh()->hasRole(PlatformRole::ReportViewer->value))->toBeTrue()
            ->and(AuditLog::query()
                ->where('action', 'access.role_assigned')
                ->where('actor_id', $this->actor->id)
                ->where('reason', 'Reassigned to the SEO team.')
                ->exists())->toBeTrue();
    });

    it('assigns a custom, database-backed role alongside the fixed twenty-one', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);
        // access.view is within SystemAdministrator's own authority, unlike
        // sms.view -- ManageCustomRole::create() and the later role grant
        // both cap a non-Super-Admin actor to permissions they hold.
        app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, ['access.view'], 'New regional structure.');

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $subject->public_id), [
                'roles' => ['regional_manager'],
                'reason' => 'Moved to the regional structure.',
            ])
            ->assertRedirect();

        expect($subject->fresh()->hasRole('regional_manager'))->toBeTrue();
    });

    it('lets an actor change their own roles, unlike a sign-in status change', function () {
        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $this->actor->public_id), [
                'roles' => [PlatformRole::SystemAdministrator->value, PlatformRole::ReportViewer->value],
                'reason' => 'Taking on reporting too.',
            ])
            ->assertRedirect();

        expect($this->actor->fresh()->hasRole(PlatformRole::ReportViewer->value))->toBeTrue();
    });

    it('lets a Super Admin demote another Super Admin while one still remains', function () {
        $rootAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
        $otherAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($rootAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $otherAdmin->public_id), [
                'roles' => [PlatformRole::SeoManager->value],
                'reason' => 'One Super Admin is enough for now.',
            ])
            ->assertRedirect();

        expect($otherAdmin->fresh()->hasRole(PlatformRole::SuperAdmin->value))->toBeFalse();
    });

    it('refuses anyone but a Super Admin acting on a Super Admin, by direct URL', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $superAdmin->public_id), [
                'roles' => [PlatformRole::SeoManager->value],
                'reason' => 'Trying it without being a Super Admin.',
            ])
            ->assertForbidden();
    });

    it('refuses a change from somebody without access.edit, by direct URL', function () {
        $reader = testPlatformStaff(PlatformRole::SeoManager);
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($reader)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $subject->public_id), [
                'roles' => [PlatformRole::KycManager->value],
                'reason' => 'Should be refused.',
            ])
            ->assertForbidden();

        expect($subject->fresh()->hasRole(PlatformRole::SmsManager->value))->toBeTrue();
    });

    it('rejects a role value that is not one of the twenty-one cases', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.staff.roles.update', $subject->public_id), [
                'roles' => ['made-up-role'],
                'reason' => 'Should be refused.',
            ])
            ->assertSessionHasErrors('roles.0');
    });
});

describe('sign-in status', function () {
    it('requires a recently confirmed password to suspend', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->post(route('admin.staff.suspend', $subject->public_id), ['reason' => 'Investigating.'])
            ->assertRedirect(route('password.confirm'));

        expect($subject->fresh()->identity_status->value)->toBe('active');
    });

    it('suspends, then reactivates, a staff member\'s sign-in', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.suspend', $subject->public_id), ['reason' => 'Investigating a report.'])
            ->assertRedirect();

        expect($subject->fresh()->identity_status->value)->toBe('suspended');

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.activate', $subject->public_id), ['reason' => 'Investigation closed.'])
            ->assertRedirect();

        expect($subject->fresh()->identity_status->value)->toBe('active');
    });

    it('permanently deactivates a staff member\'s sign-in', function () {
        $subject = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.deactivate', $subject->public_id), ['reason' => 'No longer with the company.'])
            ->assertRedirect();

        expect($subject->fresh()->identity_status->value)->toBe('closed');
    });

    it('refuses an actor changing their own sign-in status, by direct URL', function () {
        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.suspend', $this->actor->public_id), ['reason' => 'Trying it on myself.'])
            ->assertForbidden();

        expect($this->actor->fresh()->identity_status->value)->toBe('active');
    });

    it('refuses anyone but a Super Admin suspending a Super Admin, by direct URL', function () {
        $superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($this->actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.suspend', $superAdmin->public_id), ['reason' => 'Trying it anyway.'])
            ->assertForbidden();
    });

    it('refuses to deactivate the platform\'s last active Super Admin', function () {
        $lastAdmin = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($lastAdmin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.staff.deactivate', $lastAdmin->public_id), ['reason' => 'Should be refused.']);

        // Refused by the policy first (self-change on lock's ability), which
        // is exactly why the last-Super-Admin guard in ChangeIdentityAccess
        // is defense-in-depth rather than the only line here -- confirm the
        // state never moved either way.
        expect($lastAdmin->fresh()->identity_status->value)->toBe('active');
    });
});
