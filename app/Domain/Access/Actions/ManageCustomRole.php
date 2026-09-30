<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates, edits, clones and archives a *custom* platform role -- one that
 * is not among the twenty-one fixed {@see PlatformRole} enum cases (Role
 * and Permission management). The twenty-one themselves are never touched
 * here: `PlatformRole` is a pure PHP enum and {@see RolesAndPermissionsSeeder}
 * is the only thing that ever writes their permission set, so this class's
 * whole job is the roles that exist *only* as `is_system = false` database
 * rows.
 *
 * A custom role's permission bundle was invented by whoever created it, not
 * hand-declared and reviewed the way the fixed twenty-one are -- so unlike
 * {@see AssignPlatformRole}'s treatment of a fixed role, the actor's own
 * authority is the ceiling on every permission a custom role may carry, on
 * creation and on every later edit, not only at the moment it is granted to
 * someone. Otherwise anybody holding `access.edit` could bundle a
 * permission they do not hold into a role, granted to themselves once
 * created, and the ceiling {@see AssignPlatformRole} enforces at grant time
 * would arrive too late to matter for their own account.
 */
class ManageCustomRole
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  list<string>  $permissionNames
     */
    public function create(User $actor, string $name, ?string $description, array $permissionNames, string $reason): Role
    {
        $this->guardReason($reason);
        $this->guardName($name);
        $this->assertWithinActorAuthority($actor, $permissionNames);

        return $this->database->transaction(function () use ($actor, $name, $description, $permissionNames, $reason) {
            /** @var Role $role */
            $role = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'account_id' => null,
                'is_system' => false,
                'description' => $description,
            ]);

            $role->syncPermissions($permissionNames);

            $this->forgetCache();

            $this->audit->handle(new AuditEntry(
                action: 'access.role_created',
                actorId: $actor->id,
                auditableType: Role::class,
                auditableId: $role->id,
                after: ['name' => $name, 'description' => $description, 'permissions' => $permissionNames],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));

            return $role;
        });
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function update(User $actor, Role $role, string $name, ?string $description, array $permissionNames, string $reason): Role
    {
        $this->guardReason($reason);
        $this->guardCustom($role);
        $this->assertWithinActorAuthority($actor, $permissionNames);

        if ($role->name !== $name) {
            $this->guardName($name);
        }

        return $this->database->transaction(function () use ($actor, $role, $name, $description, $permissionNames, $reason) {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);

            $before = [
                'name' => $locked->name,
                'description' => $locked->description,
                'permissions' => $locked->permissions->pluck('name')->values()->all(),
            ];

            $locked->forceFill(['name' => $name, 'description' => $description])->save();
            $locked->syncPermissions($permissionNames);

            $this->forgetCache();

            $this->audit->handle(new AuditEntry(
                action: 'access.role_updated',
                actorId: $actor->id,
                auditableType: Role::class,
                auditableId: $locked->id,
                before: $before,
                after: ['name' => $name, 'description' => $description, 'permissions' => $permissionNames],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));

            return $locked;
        });
    }

    public function clone(User $actor, Role $source, string $name, ?string $description, string $reason): Role
    {
        /** @var list<string> $permissionNames */
        $permissionNames = $source->permissions->pluck('name')->values()->all();

        return $this->create($actor, $name, $description, $permissionNames, $reason);
    }

    public function archive(User $actor, Role $role, string $reason): void
    {
        $this->guardReason($reason);
        $this->guardCustom($role);

        $this->database->transaction(function () use ($actor, $role, $reason) {
            /** @var Role $locked */
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);

            if ($locked->archived_at !== null) {
                throw new InvalidArgumentException('This role is already archived.');
            }

            if (User::role($locked->name)->exists()) {
                throw new InvalidArgumentException('This role cannot be archived while a platform staff member still holds it.');
            }

            $archivedAt = now();
            $locked->forceFill(['archived_at' => $archivedAt])->save();

            $this->audit->handle(new AuditEntry(
                action: 'access.role_archived',
                actorId: $actor->id,
                auditableType: Role::class,
                auditableId: $locked->id,
                before: ['archived_at' => null],
                after: ['archived_at' => $archivedAt->toIso8601String()],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));
        });
    }

    protected function guardReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('This action requires a recorded reason.');
        }
    }

    protected function guardName(string $name): void
    {
        if (PlatformRole::tryFrom($name) !== null) {
            throw new InvalidArgumentException('That name belongs to one of the platform\'s fixed roles.');
        }

        if (Role::where('name', $name)->where('guard_name', 'web')->whereNull('account_id')->exists()) {
            throw new InvalidArgumentException('A role with that name already exists.');
        }
    }

    protected function guardCustom(Role $role): void
    {
        if ($role->is_system) {
            throw new InvalidArgumentException('A system role cannot be edited or archived.');
        }
    }

    /**
     * @param  list<string>  $permissionNames
     */
    protected function assertWithinActorAuthority(User $actor, array $permissionNames): void
    {
        if ($actor->hasRole(PlatformRole::SuperAdmin->value)) {
            return;
        }

        foreach ($permissionNames as $permission) {
            if (! $actor->can($permission)) {
                throw new InvalidArgumentException(
                    "This role would carry the [{$permission}] permission, which you do not hold yourself."
                );
            }
        }
    }

    protected function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
