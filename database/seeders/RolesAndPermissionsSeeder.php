<?php

namespace Database\Seeders;

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the platform permission catalogue and the twenty roles from §32.1.
 *
 * Idempotent: safe to re-run after adding a module or changing a role's grants.
 * Permissions are never deleted here — a permission that disappears would
 * silently detach itself from every role holding it, and the resulting hole is
 * invisible until someone cannot do their job.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $this->seedPermissions();
            $this->seedRoles();
        });

        // Spatie caches aggressively; a stale cache after seeding would hand
        // people yesterday's access (D2).
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function seedPermissions(): void
    {
        $existing = Permission::query()->pluck('name')->all();

        $missing = array_diff(PermissionCatalogue::all(), $existing);

        foreach ($missing as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        $this->command->info(sprintf(
            'Permissions: %d in catalogue, %d created, %d already present.',
            count(PermissionCatalogue::all()),
            count($missing),
            count($existing),
        ));
    }

    protected function seedRoles(): void
    {
        foreach (PlatformRole::cases() as $platformRole) {
            /** @var Role $role */
            $role = Role::findOrCreate($platformRole->value, 'web');

            // Platform roles are not bound to an account (D2). With Spatie's
            // teams feature enabled this must be explicit, or the role would be
            // scoped to whichever account happened to be active.
            $role->forceFill(['account_id' => null])->save();

            // syncPermissions rather than givePermissionTo, so a grant removed
            // from the catalogue is actually revoked on the next seed.
            $role->syncPermissions($platformRole->permissions());
        }

        $this->command->info(sprintf('Roles: %d seeded.', count(PlatformRole::cases())));
    }
}
