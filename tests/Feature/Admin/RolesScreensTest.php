<?php

use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listing and inspecting the twenty-one PlatformRole cases (commit-order
 * item 6). Read-only: there is no route here that writes anything.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('redirects a guest to the login page', function () {
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
    $this->get(route('admin.roles.show', 'sms_manager'))->assertRedirect(route('login'));
});

it('is closed to staff without access.view', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

it('is closed to a business user', function () {
    $account = testBusinessAccount();

    $this->actingAs($account->owner)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

it('lists all twenty-one roles for staff holding access.view', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/index')
            ->has('roles', 21));
});

it('counts an assigned role\'s holders and never marks it protected', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);
    testPlatformStaff(PlatformRole::SmsManager);
    testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $roles = collect($page->toArray()['props']['roles']);
            $sms = $roles->firstWhere('key', 'sms_manager');

            expect($sms['is_protected'])->toBeFalse()
                ->and($sms['holder_count'])->toBe(2)
                ->and($sms['permission_count'])->toBeGreaterThan(0);
        });
});

it('shows a role\'s permission grants grouped by module, and who holds it', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);
    $smsManager = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'sms_manager'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($smsManager) {
            $page->component('admin/roles/show')
                ->where('role.key', 'sms_manager')
                ->where('role.is_protected', false)
                ->has('holders', 1)
                ->where('holders.0.public_id', $smsManager->public_id)
                ->has('permissionGroups');

            $groups = collect($page->toArray()['props']['permissionGroups']);

            expect($groups->pluck('module'))->toContain('SMS');
        });
});

it('marks Super Admin protected, and does not list it as individual permission rows', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'super_admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/roles/show')
            ->where('role.is_protected', true)
            ->where('permissionGroups', []));
});

it('tells a dotted module value apart from the plain one it prefixes', function () {
    /*
     * Regression: Module::SupplierKyc's own value ("supplier.kyc") contains
     * a dot, so "supplier.kyc.view" was briefly parsed as module "supplier"
     * action "kyc.view" -- a real module, so the mistake produced no error
     * for a modern PHP array key, but PermissionAction::from('kyc.view')
     * throws a ValueError, and Supplier Manager holds exactly this
     * permission.
     */
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'supplier_manager'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $groups = collect($page->toArray()['props']['permissionGroups']);

            expect($groups->pluck('module'))
                ->toContain('Supplier KYC')
                ->toContain('Suppliers');

            $supplierKyc = $groups->firstWhere('module', 'Supplier KYC');

            expect($supplierKyc['actions'])->toContain('View')
                ->and($supplierKyc['actions'])->toContain('Review');
        });
});

it('404s an unknown role key rather than guessing', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.roles.show', 'not-a-real-role'))
        ->assertNotFound();
});

/*
 * Creating, editing, cloning and archiving a custom role through the HTTP
 * boundary -- the write half of Role and Permission management. Every
 * safety guard is exercised at the unit level in ManageCustomRoleTest;
 * these tests cover the permission gate and the confirmed-password
 * requirement these screens have to hold to.
 */
describe('custom role management', function () {
    it('is closed to staff without access.edit', function () {
        $reader = testPlatformStaff(PlatformRole::SmsManager);

        $this->actingAs($reader)->get(route('admin.roles.create'))->assertForbidden();

        $this->actingAs($reader)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.roles.create'))
            ->post(route('admin.roles.store'), [
                'name' => 'regional_manager',
                'permissions' => [],
                'reason' => 'Should be refused.',
            ])
            ->assertForbidden();
    });

    it('requires a recently confirmed password', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)
            ->post(route('admin.roles.store'), [
                'name' => 'regional_manager',
                'permissions' => [],
                'reason' => 'New regional structure.',
            ])
            ->assertRedirect(route('password.confirm'));

        expect(Role::where('name', 'regional_manager')->exists())->toBeFalse();
    });

    it('creates a custom role and lists it alongside the fixed twenty-one', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.roles.store'), [
                'name' => 'regional_manager',
                'description' => 'Oversees one region.',
                'permissions' => ['sms.view'],
                'reason' => 'New regional structure.',
            ])
            ->assertRedirect(route('admin.roles.show', 'regional_manager'));

        $this->actingAs($staff)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/roles/index')
                ->has('roles', 22));
    });

    it('shows a custom role as editable, with only the actor\'s own permissions offered', function () {
        // SystemAdministrator holds access.view (so it can reach this
        // screen and manage the role) but nothing under Module::Wallet --
        // editablePermissionGroups must reflect that ceiling.
        $staff = testPlatformStaff(PlatformRole::SystemAdministrator);
        app(ManageCustomRole::class)->create($staff, 'access_reader', null, ['access.view'], 'Create.');

        $this->actingAs($staff)
            ->get(route('admin.roles.show', 'access_reader'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $page->component('admin/roles/show')
                    ->where('role.type', 'custom')
                    ->where('can.manage', true)
                    ->has('editablePermissionGroups');

                $groups = collect($page->toArray()['props']['editablePermissionGroups']);
                $names = $groups->flatMap(fn (array $g) => collect($g['permissions'])->pluck('name'));

                expect($names)->toContain('access.view')
                    ->and($names)->not->toContain('wallet.adjust_wallet');
            });
    });

    it('updates a custom role\'s permissions', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);
        app(ManageCustomRole::class)->create($staff, 'regional_manager', null, ['sms.view'], 'Create.');

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.roles.update', 'regional_manager'), [
                'name' => 'regional_manager',
                'description' => 'Updated.',
                'permissions' => ['sms.view', 'sms.edit'],
                'reason' => 'Expanding the role.',
            ])
            ->assertRedirect(route('admin.roles.show', 'regional_manager'));

        expect(Role::where('name', 'regional_manager')->firstOrFail()->permissions->pluck('name')->all())
            ->toEqualCanonicalizing(['sms.view', 'sms.edit']);
    });

    it('refuses to edit a system role through the update route', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.roles.update', PlatformRole::SmsManager->value), [
                'name' => PlatformRole::SmsManager->value,
                'permissions' => [],
                'reason' => 'Should be refused.',
            ])
            ->assertNotFound();
    });

    it('clones a fixed role\'s permissions into a new custom role', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.roles.clone', PlatformRole::SmsManager->value), [
                'name' => 'sms_manager_variant',
                'reason' => 'Starting from SMS Manager.',
            ])
            ->assertRedirect(route('admin.roles.show', 'sms_manager_variant'));

        expect(Role::where('name', 'sms_manager_variant')->firstOrFail()->permissions->pluck('name')->all())
            ->toEqualCanonicalizing(PlatformRole::SmsManager->permissions());
    });

    it('archives an unheld custom role', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);
        app(ManageCustomRole::class)->create($staff, 'regional_manager', null, [], 'Create.');

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.roles.archive', 'regional_manager'), ['reason' => 'No longer needed.'])
            ->assertRedirect(route('admin.roles.index'));

        expect(Role::where('name', 'regional_manager')->firstOrFail()->archived_at)->not->toBeNull();
    });

    it('turns a refused archive (role still held) into a form error rather than a 500', function () {
        $staff = testPlatformStaff(PlatformRole::SuperAdmin);
        app(ManageCustomRole::class)->create($staff, 'regional_manager', null, [], 'Create.');
        testPlatformStaff(PlatformRole::SmsManager)->assignRole('regional_manager');

        $this->actingAs($staff)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('admin.roles.show', 'regional_manager'))
            ->delete(route('admin.roles.archive', 'regional_manager'), ['reason' => 'Should be refused.'])
            ->assertSessionHasErrors('reason');

        expect(Role::where('name', 'regional_manager')->firstOrFail()->archived_at)->toBeNull();
    });

    it('is unreachable for a business account member', function () {
        $account = testBusinessAccount();

        $this->actingAs($account->owner)->get(route('admin.roles.create'))->assertForbidden();
    });
});
