<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\ManageCustomPermission;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Models\Permission;
use App\Domain\Access\PermissionCatalogue;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The permission catalogue screen (Role and Permission management):
 * browsing every permission that exists -- the hand-declared
 * {@see PermissionCatalogue} matrix, seeded into the `permissions` table
 * with `is_system = true`, plus any custom permission an administrator has
 * created -- and creating, describing, and archiving a *custom* one,
 * delegated to {@see ManageCustomPermission}.
 *
 * A permission row existing here never implies a policy, Gate or controller
 * checks for it. `is_system` is exactly that distinction stated back to the
 * viewer: System-bound permissions are the ones {@see PermissionCatalogue}
 * declares, already wired into the application's own authorization checks;
 * a Custom/unbound one names something for future use and grants no
 * capability by itself.
 */
class PermissionsController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);
        $this->authorizeView($request);

        $search = $request->string('search')->toString();
        $module = $request->string('module')->toString();
        $type = $request->string('type')->toString();

        $moduleCase = $module !== '' ? PermissionModule::tryFrom($module) : null;

        $namesInModule = $moduleCase !== null
            ? Permission::query()->where('guard_name', 'web')->pluck('name')
                ->filter(fn (string $name) => PermissionCatalogue::moduleForOrNull($name) === $moduleCase)
                ->values()
                ->all()
            : null;

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->withCount('roles')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('description', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->when($namesInModule !== null, fn (Builder $query) => $query->whereIn('name', $namesInModule ?? []))
            ->when($type === 'system', fn (Builder $query) => $query->where('is_system', true))
            ->when($type === 'custom', fn (Builder $query) => $query->where('is_system', false))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Permission $permission) => $this->summary($permission));

        return Inertia::render('admin/permissions/index', [
            'permissions' => $permissions,
            'filters' => ['search' => $search, 'module' => $module, 'type' => $type],
            'modules' => collect(PermissionModule::cases())
                ->map(fn (PermissionModule $module) => ['value' => $module->value, 'label' => $module->label()])
                ->values(),
            'can' => ['manage' => $actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit))],
        ]);
    }

    public function store(Request $request, ManageCustomPermission $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        /** @var array{name: string, description: ?string, reason: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:'.ManageCustomPermission::NAME_PATTERN],
            'description' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->create($actor, $validated['name'], $validated['description'] ?? null, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return back()->with('success', __('access.permissions.created'));
    }

    public function update(Request $request, string $permission, ManageCustomPermission $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        $record = $this->findCustomPermission($permission);
        abort_if($record === null, 404);

        /** @var array{description: ?string, reason: string} $validated */
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->updateDescription($actor, $record, $validated['description'] ?? null, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['description' => $exception->getMessage()]);
        }

        return back()->with('success', __('access.permissions.updated'));
    }

    public function archive(Request $request, string $permission, ManageCustomPermission $action): RedirectResponse
    {
        $actor = $this->actor($request);
        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        $record = $this->findCustomPermission($permission);
        abort_if($record === null, 404);

        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->archive($actor, $record, $validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', __('access.permissions.archived'));
    }

    /**
     * @return array{name: string, module: string, action: string, description: ?string, is_system: bool, is_archived: bool, roles_count: int}
     */
    protected function summary(Permission $permission): array
    {
        [$moduleLabel, $actionLabel] = PermissionCatalogue::describe($permission->name);

        return [
            'name' => $permission->name,
            'module' => $moduleLabel,
            'action' => $actionLabel,
            'description' => $permission->description,
            'is_system' => $permission->is_system,
            'is_archived' => $permission->archived_at !== null,
            'roles_count' => $permission->roles_count,
        ];
    }

    protected function findCustomPermission(string $name): ?Permission
    {
        return Permission::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->where('is_system', false)
            ->first();
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
