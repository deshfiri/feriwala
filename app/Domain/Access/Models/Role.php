<?php

namespace App\Domain\Access\Models;

use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PlatformRole;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie's own `Role` model, extended with the columns Role and Permission
 * management adds ({@see ManageCustomRole}) --
 * the package's own documented extension point (`config('permission.models.role')`),
 * not a parallel model or a parallel table.
 *
 * @property bool $is_system Whether this is one of the twenty-one seeded
 *                           {@see PlatformRole} cases, protected
 *                           from rename, permission-set drift and deletion.
 * @property string|null $description
 * @property Carbon|null $archived_at Set once, never cleared -- an archived
 *                                    custom role stays archived.
 */
class Role extends SpatieRole
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
        ]);
    }
}
