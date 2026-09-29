<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listing and inspecting the twenty-one {@see PlatformRole} cases
 * (commit-order item 6).
 *
 * Read-only. This application has no safe way to create, clone or edit a
 * role without a code deploy -- `PlatformRole` is a pure PHP enum, and
 * {@see PermissionCatalogue}'s matrix is hand-declared, not
 * data. There is nothing to write here, only the fixed catalogue to browse
 * and search -- reported as an architectural limitation rather than worked
 * around with a parallel, database-backed role system.
 */
class RolesController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeView($request);

        $roles = collect(PlatformRole::cases())
            ->map(fn (PlatformRole $role) => $this->summary($role))
            ->values();

        return Inertia::render('admin/roles/index', [
            'roles' => $roles,
        ]);
    }

    public function show(Request $request, string $role): Response
    {
        $this->authorizeView($request);

        $platformRole = PlatformRole::tryFrom($role);

        abort_if($platformRole === null, 404);

        /*
         * Super Admin holds every permission via a Gate::before rule rather
         * than a row per permission (grantsEverything()), so listing it out
         * would only be the entire catalogue restated -- the page shows one
         * sentence instead, and there is nothing to group here at all.
         */
        $permissionGroups = $platformRole->grantsEverything()
            ? collect()
            : collect($platformRole->permissions())
                ->groupBy(fn (string $permission) => Str::before($permission, '.'))
                ->map(fn ($names, string $moduleValue) => [
                    'module' => PermissionModule::from($moduleValue)->label(),
                    'actions' => collect($names)
                        ->map(fn (string $name) => PermissionAction::from(Str::after($name, '.'))->label())
                        ->sort()
                        ->values(),
                ])
                ->sortBy('module')
                ->values();

        $holders = User::role($platformRole->value)
            ->orderBy('name')
            ->get(['public_id', 'name', 'email', 'identity_status'])
            ->map(fn (User $user) => [
                'public_id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'identity_status_label' => $user->identity_status->label(),
                'identity_status_tone' => $user->identity_status->tone(),
            ])
            ->values();

        return Inertia::render('admin/roles/show', [
            'role' => $this->summary($platformRole),
            'permissionGroups' => $permissionGroups,
            'holders' => $holders,
        ]);
    }

    /**
     * @return array{key: string, label: string, is_protected: bool, requires_two_factor: bool, permission_count: int, holder_count: int}
     */
    protected function summary(PlatformRole $role): array
    {
        return [
            'key' => $role->value,
            'label' => $role->label(),
            'is_protected' => $role->grantsEverything(),
            'requires_two_factor' => $role->requiresTwoFactor(),
            'permission_count' => $role->grantsEverything() ? count(PermissionCatalogue::all()) : count($role->permissions()),
            'holder_count' => User::role($role->value)->count(),
        ];
    }

    protected function authorizeView(Request $request): void
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::View)), 403);
    }
}
