<?php

use App\Domain\Access\Actions\ManageCustomPermission;
use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Models\Permission;
use App\Domain\Audit\Models\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Creating, describing and archiving a custom permission -- the other half
 * of Role and Permission management. Creating one never wires it into any
 * policy, Gate or controller by itself (that still needs a code change); it
 * only becomes available to bundle into a role.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actor = testPlatformStaff(PlatformRole::SuperAdmin);
});

it('creates a custom, unbound permission', function () {
    $permission = app(ManageCustomPermission::class)->create(
        actor: $this->actor,
        name: 'reporting.export_custom',
        description: 'Exports the custom regional report.',
        reason: 'New report is going live.',
    );

    expect($permission->name)->toBe('reporting.export_custom')
        ->and($permission->is_system)->toBeFalse()
        ->and($permission->description)->toBe('Exports the custom regional report.');
});

it('backfills every catalogue permission as system-bound at seed time', function () {
    expect(Permission::where('name', 'sms.view')->firstOrFail()->is_system)->toBeTrue();
});

it('refuses a name that is not a valid module.action shape', function () {
    expect(fn () => app(ManageCustomPermission::class)->create(
        actor: $this->actor,
        name: 'NotValid',
        description: null,
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a duplicate permission name', function () {
    app(ManageCustomPermission::class)->create($this->actor, 'reporting.export_custom', null, 'First.');

    expect(fn () => app(ManageCustomPermission::class)->create(
        actor: $this->actor,
        name: 'reporting.export_custom',
        description: null,
        reason: 'Second.',
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses without a recorded reason', function () {
    expect(fn () => app(ManageCustomPermission::class)->create(
        actor: $this->actor,
        name: 'reporting.export_custom',
        description: null,
        reason: '  ',
    ))->toThrow(InvalidArgumentException::class);
});

it('updates a custom permission\'s description', function () {
    $permission = app(ManageCustomPermission::class)->create($this->actor, 'reporting.export_custom', 'Old.', 'Create.');

    app(ManageCustomPermission::class)->updateDescription($this->actor, $permission, 'New.', 'Clarifying it.');

    expect($permission->fresh()->description)->toBe('New.');
});

it('refuses to edit or archive a system permission', function () {
    $systemPermission = Permission::where('name', 'sms.view')->firstOrFail();

    expect(fn () => app(ManageCustomPermission::class)->updateDescription($this->actor, $systemPermission, 'New.', 'Should be refused.'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ManageCustomPermission::class)->archive($this->actor, $systemPermission, 'Should be refused.'))
        ->toThrow(InvalidArgumentException::class);
});

it('archives a custom permission nothing holds', function () {
    $permission = app(ManageCustomPermission::class)->create($this->actor, 'reporting.export_custom', null, 'Create.');

    app(ManageCustomPermission::class)->archive($this->actor, $permission, 'No longer needed.');

    expect($permission->fresh()->archived_at)->not->toBeNull();
});

it('refuses to archive a permission still assigned to a role', function () {
    $permission = app(ManageCustomPermission::class)->create($this->actor, 'reporting.export_custom', null, 'Create.');

    app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, ['reporting.export_custom'], 'Uses the new permission.');

    expect(fn () => app(ManageCustomPermission::class)->archive($this->actor, $permission, 'Should be refused.'))
        ->toThrow(InvalidArgumentException::class);

    expect($permission->fresh()->archived_at)->toBeNull();
});

it('a custom permission grants nothing by itself, whoever holds the role it is bundled into', function () {
    /*
     * The unbound/system-bound distinction (Item 3): creating a custom
     * permission and assigning it to a role a staff member holds must not
     * suddenly unlock some unrelated capability that happens to be gated
     * on the same permission name -- because nothing in the application
     * checks this name at all.
     */
    app(ManageCustomPermission::class)->create($this->actor, 'reporting.export_custom', null, 'Create.');
    app(ManageCustomRole::class)->create($this->actor, 'custom_reporter', null, ['reporting.export_custom'], 'New reporting role.');

    $holder = testPlatformStaff(PlatformRole::SmsManager);
    $holder->assignRole('custom_reporter');

    expect($holder->fresh()->can('reporting.export_custom'))->toBeTrue()
        // Holding the unbound permission must not imply any real, checked
        // capability the holder did not otherwise have.
        ->and($holder->fresh()->can('wallet.adjust_wallet'))->toBeFalse()
        ->and($holder->fresh()->can('access.edit'))->toBeFalse();
});

it('records actor, before, after and reason for every write', function () {
    $permission = app(ManageCustomPermission::class)->create($this->actor, 'reporting.export_custom', null, 'Creating it.');

    app(ManageCustomPermission::class)->updateDescription($this->actor, $permission, 'Described now.', 'Editing it.');
    app(ManageCustomPermission::class)->archive($this->actor, $permission, 'Archiving it.');

    $created = AuditLog::query()->where('action', 'access.permission_created')->where('auditable_id', $permission->id)->firstOrFail();
    $updated = AuditLog::query()->where('action', 'access.permission_updated')->where('auditable_id', $permission->id)->firstOrFail();
    $archived = AuditLog::query()->where('action', 'access.permission_archived')->where('auditable_id', $permission->id)->firstOrFail();

    expect($created->actor_id)->toBe($this->actor->id)
        ->and($created->reason)->toBe('Creating it.')
        ->and($updated->before['description'])->toBeNull()
        ->and($updated->after['description'])->toBe('Described now.')
        ->and($archived->reason)->toBe('Archiving it.');
});
