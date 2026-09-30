<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\ManageCustomRole;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\Models\Role;
use App\Domain\Access\PermissionCatalogue;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Role management (Role and Permission management): browsing and inspecting
 * the twenty-one fixed {@see PlatformRole} cases exactly as before, plus
 * creating, editing, cloning and archiving a *custom* role -- one that
 * exists only as an `is_system = false` database row, delegated entirely to
 * {@see ManageCustomRole}.
 *
 * The fixed twenty-one stay exactly what they were: `PlatformRole` is a pure
 * PHP enum and {@see PermissionCatalogue}'s matrix is hand-declared, not
 * data, so there is nothing to write for them here. A custom role is
 * addressed by its `name`, the same way `show()` already resolved a fixed
 * role -- never by database id (§ no IDs in public URLs).
 */
class RolesController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->authorizeView($request);

        $fixed = collect(PlatformRole::cases())
            ->map(fn (PlatformRole $role) => $this->summary($role));

        $custom = Role::query()
            ->where('guard_name', 'web')
            ->where('is_system', false)
            ->whereNull('account_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->customSummary($role));

        return Inertia::render('admin/roles/index', [
            'roles' => $fixed->concat($custom)->values(),
            'can' => ['create' => $actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit))],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        return Inertia::render('admin/roles/create', [
            'permissionGroups' => $this->permissionOptions($this->assignablePermissionNames($actor)),
        ]);
    }

    public function store(Request $request, ManageCustomRole $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        /** @var array{name: string, description: ?string, permissions?: list<string>, reason: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in($this->assignablePermissionNames($actor))],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $role = $action->create(
                actor: $actor,
                name: $validated['name'],
                description: $validated['description'] ?? null,
                permissionNames: $validated['permissions'] ?? [],
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.show', $role->name)
            ->with('success', __('access.roles.created'));
    }

    public function show(Request $request, string $role): Response
    {
        $actor = $this->actor($request);
        $this->authorizeView($request);

        $platformRole = PlatformRole::tryFrom($role);
        $canEdit = $actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit));

        if ($platformRole !== null) {
            $permissionGroups = $platformRole->grantsEverything()
                ? []
                : PermissionCatalogue::groupedByModule($platformRole->permissions());

            return Inertia::render('admin/roles/show', [
                'role' => $this->summary($platformRole),
                'permissionGroups' => $permissionGroups,
                'holders' => $this->holders($platformRole->value),
                'can' => ['manage' => false, 'clone' => $canEdit],
            ]);
        }

        $customRole = $this->findCustomRole($role);

        abort_if($customRole === null, 404);

        $canManage = $canEdit && $customRole->archived_at === null;

        return Inertia::render('admin/roles/show', [
            'role' => $this->customSummary($customRole),
            'permissionGroups' => PermissionCatalogue::groupedByModule($customRole->permissions->pluck('name')->all()),
            'holders' => $this->holders($customRole->name),
            'editablePermissionGroups' => $this->permissionOptions($this->assignablePermissionNames($actor)),
            'assignedPermissionNames' => $customRole->permissions->pluck('name')->values(),
            'can' => ['manage' => $canManage, 'clone' => $canEdit],
        ]);
    }

    public function update(Request $request, string $role, ManageCustomRole $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        $customRole = $this->findCustomRole($role);
        abort_if($customRole === null, 404);

        /** @var array{name: string, description: ?string, permissions?: list<string>, reason: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in($this->assignablePermissionNames($actor))],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->update(
                actor: $actor,
                role: $customRole,
                name: $validated['name'],
                description: $validated['description'] ?? null,
                permissionNames: $validated['permissions'] ?? [],
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.show', $validated['name'])
            ->with('success', __('access.roles.updated'));
    }

    public function clone(Request $request, string $role, ManageCustomRole $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        $source = $this->resolveAnyRole($role);
        abort_if($source === null, 404);

        /** @var array{name: string, description: ?string, reason: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $cloned = $action->clone(
                actor: $actor,
                source: $source,
                name: $validated['name'],
                description: $validated['description'] ?? null,
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.show', $cloned->name)
            ->with('success', __('access.roles.cloned'));
    }

    public function archive(Request $request, string $role, ManageCustomRole $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        $customRole = $this->findCustomRole($role);
        abort_if($customRole === null, 404);

        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->archive($actor, $customRole, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.roles.index')
            ->with('success', __('access.roles.archived_flash'));
    }

    /**
     * A fixed {@see PlatformRole} enum case, one entry per role summary.
     *
     * @return array{key: string, label: string, type: string, is_protected: bool, is_archived: bool, requires_two_factor: bool, permission_count: int, holder_count: int}
     */
    protected function summary(PlatformRole $role): array
    {
        return [
            'key' => $role->value,
            'label' => $role->label(),
            'type' => 'system',
            'is_protected' => $role->grantsEverything(),
            'is_archived' => false,
            'requires_two_factor' => $role->requiresTwoFactor(),
            'permission_count' => $role->grantsEverything() ? count(PermissionCatalogue::all()) : count($role->permissions()),
            'holder_count' => User::role($role->value)->count(),
        ];
    }

    /**
     * @return array{key: string, label: string, type: string, description: ?string, is_protected: bool, is_archived: bool, requires_two_factor: bool, permission_count: int, holder_count: int}
     */
    protected function customSummary(Role $role): array
    {
        return [
            'key' => $role->name,
            'label' => $role->name,
            'type' => 'custom',
            'description' => $role->description,
            'is_protected' => false,
            'is_archived' => $role->archived_at !== null,
            'requires_two_factor' => false,
            'permission_count' => $role->permissions->count(),
            'holder_count' => User::role($role->name)->count(),
        ];
    }

    /**
     * @return array<int, array{public_id: string, name: string, email: string, identity_status_label: string, identity_status_tone: string}>
     */
    protected function holders(string $roleName): array
    {
        return User::role($roleName)
            ->orderBy('name')
            ->get(['public_id', 'name', 'email', 'identity_status'])
            ->map(fn (User $user) => [
                'public_id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'identity_status_label' => $user->identity_status->label(),
                'identity_status_tone' => $user->identity_status->tone(),
            ])
            ->values()
            ->all();
    }

    protected function findCustomRole(string $name): ?Role
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->where('is_system', false)
            ->whereNull('account_id')
            ->first();
    }

    protected function resolveAnyRole(string $name): ?Role
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->whereNull('account_id')
            ->first();
    }

    /**
     * Every permission name an actor may bundle into a custom role -- every
     * non-archived permission for a Super Admin, and only the permissions the
     * actor personally holds otherwise, mirroring the ceiling
     * {@see ManageCustomRole} itself enforces so the form never offers a
     * choice the server would refuse.
     *
     * @return list<string>
     */
    protected function assignablePermissionNames(User $actor): array
    {
        $names = Permission::query()
            ->where('guard_name', 'web')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($actor->hasRole(PlatformRole::SuperAdmin->value)) {
            return array_values($names);
        }

        return array_values(array_filter($names, fn (string $name) => $actor->can($name)));
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array<int, array{module: string, permissions: array<int, array{name: string, label: string}>}>
     */
    protected function permissionOptions(array $permissionNames): array
    {
        $byModule = [];

        foreach ($permissionNames as $name) {
            [$moduleLabel, $actionLabel] = PermissionCatalogue::describe($name);
            $byModule[$moduleLabel][] = ['name' => $name, 'label' => $actionLabel];
        }

        $groups = [];

        foreach ($byModule as $moduleLabel => $permissions) {
            usort($permissions, fn (array $a, array $b) => $a['label'] <=> $b['label']);

            $groups[] = ['module' => $moduleLabel, 'permissions' => $permissions];
        }

        usort($groups, fn (array $a, array $b) => $a['module'] <=> $b['module']);

        return $groups;
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }

    protected function authorizeView(Request $request): void
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::View)), 403);
    }
}
