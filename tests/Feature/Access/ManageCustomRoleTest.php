<?php

use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Creating, editing, cloning and archiving a custom role -- the database
 * half of Role and Permission management, alongside the twenty-one fixed
 * PlatformRole cases {@see AssignPlatformRole} already covers.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->actor = testPlatformStaff(PlatformRole::SuperAdmin);
});

it('creates a custom role with the given permissions', function () {
    $role = app(ManageCustomRole::class)->create(
        actor: $this->actor,
        name: 'regional_manager',
        description: 'Oversees one region.',
        permissionNames: ['sms.view', 'sms.create'],
        reason: 'New regional structure.',
    );

    expect($role->name)->toBe('regional_manager')
        ->and($role->is_system)->toBeFalse()
        ->and($role->description)->toBe('Oversees one region.')
        ->and($role->permissions->pluck('name')->all())->toEqualCanonicalizing(['sms.view', 'sms.create']);
});

it('refuses a name colliding with a fixed PlatformRole', function () {
    expect(fn () => app(ManageCustomRole::class)->create(
        actor: $this->actor,
        name: PlatformRole::SmsManager->value,
        description: null,
        permissionNames: [],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a duplicate custom role name', function () {
    app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, [], 'First.');

    expect(fn () => app(ManageCustomRole::class)->create(
        actor: $this->actor,
        name: 'regional_manager',
        description: null,
        permissionNames: [],
        reason: 'Second.',
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses without a recorded reason', function () {
    expect(fn () => app(ManageCustomRole::class)->create(
        actor: $this->actor,
        name: 'regional_manager',
        description: null,
        permissionNames: [],
        reason: '   ',
    ))->toThrow(InvalidArgumentException::class);
});

it('caps a non-Super-Admin actor to permissions they personally hold', function () {
    $actor = testPlatformStaff(PlatformRole::SmsManager);

    expect(fn () => app(ManageCustomRole::class)->create(
        actor: $actor,
        name: 'regional_manager',
        description: null,
        permissionNames: ['wallet.adjust_wallet'],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);

    app(ManageCustomRole::class)->create(
        actor: $actor,
        name: 'sms_reader',
        description: null,
        permissionNames: ['sms.view'],
        reason: 'Within the actor\'s own authority.',
    );

    expect(Role::where('name', 'sms_reader')->exists())->toBeTrue();
});

it('updates a custom role\'s name, description and permission set', function () {
    $role = app(ManageCustomRole::class)->create($this->actor, 'regional_manager', 'Old.', ['sms.view'], 'Create.');

    app(ManageCustomRole::class)->update(
        actor: $this->actor,
        role: $role,
        name: 'area_manager',
        description: 'New.',
        permissionNames: ['sms.view', 'sms.edit'],
        reason: 'Renamed and expanded.',
    );

    $fresh = $role->fresh();

    expect($fresh->name)->toBe('area_manager')
        ->and($fresh->description)->toBe('New.')
        ->and($fresh->permissions->pluck('name')->all())->toEqualCanonicalizing(['sms.view', 'sms.edit']);
});

it('refuses to edit a system role', function () {
    $systemRole = Role::where('name', PlatformRole::SmsManager->value)->firstOrFail();

    expect(fn () => app(ManageCustomRole::class)->update(
        actor: $this->actor,
        role: $systemRole,
        name: 'renamed',
        description: null,
        permissionNames: [],
        reason: 'Should be refused.',
    ))->toThrow(InvalidArgumentException::class);
});

it('clones a role\'s permission set under a new name', function () {
    $source = app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, ['sms.view', 'sms.edit'], 'Create.');

    $clone = app(ManageCustomRole::class)->clone(
        actor: $this->actor,
        source: $source,
        name: 'regional_manager_copy',
        description: 'A copy.',
        reason: 'Starting from the original.',
    );

    expect($clone->name)->toBe('regional_manager_copy')
        ->and($clone->permissions->pluck('name')->all())->toEqualCanonicalizing(['sms.view', 'sms.edit']);
});

it('clones a fixed PlatformRole\'s permission set into a new custom role', function () {
    $source = Role::where('name', PlatformRole::SmsManager->value)->firstOrFail();

    $clone = app(ManageCustomRole::class)->clone(
        actor: $this->actor,
        source: $source,
        name: 'sms_manager_variant',
        description: null,
        reason: 'Starting from SMS Manager.',
    );

    expect($clone->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(PlatformRole::SmsManager->permissions());
});

it('archives an unheld custom role', function () {
    $role = app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, [], 'Create.');

    app(ManageCustomRole::class)->archive($this->actor, $role, 'No longer needed.');

    expect($role->fresh()->archived_at)->not->toBeNull();
});

it('refuses to archive a role still held by someone', function () {
    $role = app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, [], 'Create.');
    $holder = testPlatformStaff(PlatformRole::SmsManager);
    $holder->assignRole('regional_manager');

    expect(fn () => app(ManageCustomRole::class)->archive($this->actor, $role, 'Should be refused.'))
        ->toThrow(InvalidArgumentException::class);

    expect($role->fresh()->archived_at)->toBeNull();
});

it('refuses to archive a system role', function () {
    $systemRole = Role::where('name', PlatformRole::SmsManager->value)->firstOrFail();

    expect(fn () => app(ManageCustomRole::class)->archive($this->actor, $systemRole, 'Should be refused.'))
        ->toThrow(InvalidArgumentException::class);
});

it('records actor, before, after and reason for every write', function () {
    $role = app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, ['sms.view'], 'Creating it.');

    app(ManageCustomRole::class)->update($this->actor, $role, 'regional_manager', 'Described now.', ['sms.view', 'sms.edit'], 'Editing it.');
    app(ManageCustomRole::class)->archive($this->actor, $role, 'Archiving it.');

    $created = AuditLog::query()->where('action', 'access.role_created')->where('auditable_id', $role->id)->firstOrFail();
    $updated = AuditLog::query()->where('action', 'access.role_updated')->where('auditable_id', $role->id)->firstOrFail();
    $archived = AuditLog::query()->where('action', 'access.role_archived')->where('auditable_id', $role->id)->firstOrFail();

    expect($created->actor_id)->toBe($this->actor->id)
        ->and($created->reason)->toBe('Creating it.')
        ->and($updated->before['permissions'])->toBe(['sms.view'])
        ->and($updated->after['permissions'])->toEqualCanonicalizing(['sms.view', 'sms.edit'])
        ->and($archived->reason)->toBe('Archiving it.');
});

it('invalidates the permission cache so an edited role\'s new permission takes effect without manual intervention', function () {
    $role = app(ManageCustomRole::class)->create($this->actor, 'regional_manager', null, ['sms.view'], 'Create.');

    // A plain staff login holding only the custom role -- not a fixed
    // PlatformRole that might already carry sms.edit on its own, which
    // would make the "before" assertion below meaningless.
    $holder = User::factory()->staff()->create();
    $holder->assignRole('regional_manager');

    // Warm Spatie's own permission cache on the pre-edit permission set.
    expect($holder->fresh()->can('sms.view'))->toBeTrue()
        ->and($holder->fresh()->can('sms.edit'))->toBeFalse();

    app(ManageCustomRole::class)->update($this->actor, $role, 'regional_manager', null, ['sms.view', 'sms.edit'], 'Adding edit rights.');

    // No forgetCachedPermissions() call here -- ManageCustomRole::update()
    // must have already invalidated the cache warmed above.
    expect($holder->fresh()->can('sms.edit'))->toBeTrue();
});
