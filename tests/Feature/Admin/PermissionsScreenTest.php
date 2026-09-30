<?php

use App\Domain\Access\Actions\ManageCustomPermission;
use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Models\Permission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The permission catalogue screen (Role and Permission management):
 * browsing every permission, System-bound and Custom/unbound alike, and
 * managing the custom ones.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('redirects a guest to the login page', function () {
    $this->get(route('admin.permissions.index'))->assertRedirect(route('login'));
});

it('is closed to staff without access.view', function () {
    $staff = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($staff)->get(route('admin.permissions.index'))->assertForbidden();
});

it('is closed to a business user', function () {
    $account = testBusinessAccount();

    $this->actingAs($account->owner)->get(route('admin.permissions.index'))->assertForbidden();
});

it('lists every catalogue permission as system-bound', function () {
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.permissions.index', ['search' => 'sms.view']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $page->component('admin/permissions/index');

            $rows = collect($page->toArray()['props']['permissions']['data']);
            $smsView = $rows->firstWhere('name', 'sms.view');

            expect($smsView)->not->toBeNull()
                ->and($smsView['is_system'])->toBeTrue()
                ->and($smsView['module'])->toBe('SMS');
        });
});

it('filters the catalogue by module without a dotted module value swallowing its own parent', function () {
    /*
     * Regression guard mirroring RolesScreensTest's own module-parsing
     * check: filtering by "supplier" must not also return
     * "supplier.kyc.*" rows, since PermissionCatalogue matches modules by
     * longest prefix, not a naive string search.
     */
    $staff = testPlatformStaff(PlatformRole::SystemAdministrator);

    $this->actingAs($staff)
        ->get(route('admin.permissions.index', ['module' => 'supplier']))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $names = collect($page->toArray()['props']['permissions']['data'])->pluck('name');

            expect($names)->toContain('supplier.approve')
                ->and($names)->not->toContain('supplier.kyc.view');
        });
});

it('requires a recently confirmed password', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($staff)
        ->post(route('admin.permissions.store'), [
            'name' => 'reporting.export_custom',
            'reason' => 'New report is going live.',
        ])
        ->assertRedirect(route('password.confirm'));

    expect(Permission::where('name', 'reporting.export_custom')->exists())->toBeFalse();
});

it('creates a custom permission', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.permissions.store'), [
            'name' => 'reporting.export_custom',
            'description' => 'Exports the custom report.',
            'reason' => 'New report is going live.',
        ])
        ->assertRedirect();

    expect(Permission::where('name', 'reporting.export_custom')->firstOrFail()->is_system)->toBeFalse();
});

it('is closed to staff without access.edit', function () {
    $reader = testPlatformStaff(PlatformRole::SmsManager);

    $this->actingAs($reader)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('admin.permissions.store'), [
            'name' => 'reporting.export_custom',
            'reason' => 'Should be refused.',
        ])
        ->assertForbidden();
});

it('updates a custom permission\'s description', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $permission = app(ManageCustomPermission::class)->create($staff, 'reporting.export_custom', 'Old.', 'Create.');

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('admin.permissions.update', $permission->name), [
            'description' => 'New.',
            'reason' => 'Clarifying it.',
        ])
        ->assertRedirect();

    expect($permission->fresh()->description)->toBe('New.');
});

it('refuses to edit a system permission through the update route', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('admin.permissions.update', 'sms.view'), [
            'description' => 'Should be refused.',
            'reason' => 'Should be refused.',
        ])
        ->assertNotFound();
});

it('archives an unassigned custom permission', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $permission = app(ManageCustomPermission::class)->create($staff, 'reporting.export_custom', null, 'Create.');

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('admin.permissions.archive', $permission->name), ['reason' => 'No longer needed.'])
        ->assertRedirect();

    expect($permission->fresh()->archived_at)->not->toBeNull();
});

it('turns a refused archive (still assigned) into a form error rather than a 500', function () {
    $staff = testPlatformStaff(PlatformRole::SuperAdmin);
    $permission = app(ManageCustomPermission::class)->create($staff, 'reporting.export_custom', null, 'Create.');
    app(ManageCustomRole::class)->create($staff, 'custom_reporter', null, ['reporting.export_custom'], 'Uses it.');

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('admin.permissions.index'))
        ->delete(route('admin.permissions.archive', $permission->name), ['reason' => 'Should be refused.'])
        ->assertSessionHasErrors('reason');

    expect($permission->fresh()->archived_at)->toBeNull();
});
