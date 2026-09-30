<?php

namespace App\Domain\Access\Models;

use App\Domain\Access\Actions\ManageCustomPermission;
use App\Domain\Access\PermissionCatalogue;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Spatie's own `Permission` model, extended with the columns Role and
 * Permission management adds ({@see ManageCustomPermission}) --
 * the package's own documented extension point
 * (`config('permission.models.permission')`), not a parallel model or a
 * parallel table.
 *
 * @property bool $is_system Whether this is one of {@see PermissionCatalogue}'s
 *                           own hand-declared permissions, protected from
 *                           rename and deletion.
 * @property string|null $description
 * @property Carbon|null $archived_at Set once, never cleared -- an archived
 *                                    custom permission stays archived.
 */
class Permission extends SpatiePermission
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
        ]);
    }
}
