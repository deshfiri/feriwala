<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an administrator create roles and permissions beyond the fixed
 * twenty-one {@see PlatformRole} cases and the
 * hand-declared {@see PermissionCatalogue} matrix
 * (Role and Permission management).
 *
 * `is_system` marks every row {@see RolesAndPermissionsSeeder}
 * creates -- protected from rename, permission-set drift and deletion,
 * exactly the same "seeded is protected" rule the rest of this application
 * already follows. A custom row this migration makes possible (`is_system`
 * false) may be edited, its permissions changed, and archived once unused.
 *
 * `archived_at` is a soft state, never a delete: consistent with this
 * application's standing rule that nothing financial or access-related is
 * ever hard-deleted, an archived custom role or permission keeps its
 * history (audit entries, `role_has_permissions` rows for a role no one
 * holds anymore) intact rather than cascading a real `DELETE`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('guard_name');
            $table->text('description')->nullable()->after('is_system');
            $table->timestamp('archived_at')->nullable()->after('description');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('guard_name');
            $table->text('description')->nullable()->after('is_system');
            $table->timestamp('archived_at')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'description', 'archived_at']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'description', 'archived_at']);
        });
    }
};
