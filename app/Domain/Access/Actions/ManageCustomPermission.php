<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Models\Permission;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates, edits the description of, and archives a *custom* permission --
 * one that is not part of {@see PermissionCatalogue}'s
 * hand-declared matrix (Role and Permission management).
 *
 * A custom permission is deliberately inert on creation: creating the row
 * here never makes any policy, Gate or controller check for it -- that
 * still requires a code change and a deploy, exactly like adding a new
 * entry to the catalogue matrix does. The permission catalogue screen
 * labels every custom permission "unbound" for this reason, so nobody
 * mistakes "I created a permission" for "I protected a route."
 */
class ManageCustomPermission
{
    /**
     * A `module.action` name: two or more lowercase snake_case segments
     * joined by dots, matching the shape {@see PermissionCatalogue}
     * itself produces -- never validated against the catalogue's own
     * module/action enums, because the entire point of a custom permission
     * is that it names something the closed catalogue does not.
     */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function create(User $actor, string $name, ?string $description, string $reason): Permission
    {
        $this->guardReason($reason);
        $this->guardName($name);

        return $this->database->transaction(function () use ($actor, $name, $description, $reason) {
            /** @var Permission $permission */
            $permission = Permission::create([
                'name' => $name,
                'guard_name' => 'web',
                'is_system' => false,
                'description' => $description,
            ]);

            $this->forgetCache();

            $this->audit->handle(new AuditEntry(
                action: 'access.permission_created',
                actorId: $actor->id,
                auditableType: Permission::class,
                auditableId: $permission->id,
                after: ['name' => $name, 'description' => $description],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));

            return $permission;
        });
    }

    public function updateDescription(User $actor, Permission $permission, ?string $description, string $reason): Permission
    {
        $this->guardReason($reason);
        $this->guardCustom($permission);

        return $this->database->transaction(function () use ($actor, $permission, $description, $reason) {
            /** @var Permission $locked */
            $locked = Permission::query()->lockForUpdate()->findOrFail($permission->id);

            $before = ['description' => $locked->description];

            $locked->forceFill(['description' => $description])->save();

            $this->audit->handle(new AuditEntry(
                action: 'access.permission_updated',
                actorId: $actor->id,
                auditableType: Permission::class,
                auditableId: $locked->id,
                before: $before,
                after: ['description' => $description],
                reason: $reason,
                module: 'access',
                isSensitive: true,
            ));

            return $locked;
        });
    }

    public function archive(User $actor, Permission $permission, string $reason): void
    {
        $this->guardReason($reason);
        $this->guardCustom($permission);

        $this->database->transaction(function () use ($actor, $permission, $reason) {
            /** @var Permission $locked */
            $locked = Permission::query()->lockForUpdate()->findOrFail($permission->id);

            if ($locked->archived_at !== null) {
                throw new InvalidArgumentException('This permission is already archived.');
            }

            if ($locked->roles()->exists()) {
                throw new InvalidArgumentException('This permission cannot be archived while a role still holds it.');
            }

            $archivedAt = now();
            $locked->forceFill(['archived_at' => $archivedAt])->save();

            $this->forgetCache();

            $this->audit->handle(new AuditEntry(
                action: 'access.permission_archived',
                actorId: $actor->id,
                auditableType: Permission::class,
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
        if (! preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException('A permission name must look like "module.action" -- lowercase words joined by dots.');
        }

        if (Permission::where('name', $name)->where('guard_name', 'web')->exists()) {
            throw new InvalidArgumentException('A permission with that name already exists.');
        }
    }

    protected function guardCustom(Permission $permission): void
    {
        if ($permission->is_system) {
            throw new InvalidArgumentException('A system permission cannot be edited or archived.');
        }
    }

    protected function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
