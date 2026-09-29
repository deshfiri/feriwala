<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\AssignPlatformRole;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\Queries\PlatformStaffDirectory;
use App\Domain\Account\Policies\UserPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The platform staff directory, and staff role assignment (commit-order
 * item 6, the other half of the Roles & Permissions screens alongside
 * {@see RolesController}).
 *
 * Every safety guard for the assignment itself lives in
 * {@see AssignPlatformRole} and {@see UserPolicy::assignRole()},
 * not here -- this controller only validates the request shape and turns
 * the action's own refusal into a form error, the same way
 * {@see IdentityAccessController} already does for locking a login.
 */
class StaffAccessController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request, PlatformStaffDirectory $directory): Response
    {
        $this->authorizeView($request);

        $search = $request->string('search')->toString();

        $staff = $directory->builder($search)
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user) => $this->summary($user));

        return Inertia::render('admin/staff-access/index', [
            'staff' => $staff,
            'filters' => ['search' => $search],
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorizeView($request);
        $this->abortIfNotPlatformStaff($user);

        $actor = $this->actor($request);

        return Inertia::render('admin/staff-access/show', [
            'staffMember' => $this->summary($user),
            'permissionGroups' => PermissionCatalogue::groupedByModule($user->getAllPermissions()->pluck('name')->all()),
            /*
             * Super Admin is offered only to an actor who already holds it
             * -- granting it is how a lesser role would make itself
             * unrestricted, so {@see AssignPlatformRole} refuses this at
             * the point of assignment regardless; leaving it out of the
             * list here is the UI half of the same rule, not the guard
             * itself.
             */
            'roles' => collect(PlatformRole::cases())
                ->reject(fn (PlatformRole $role) => $role->grantsEverything() && ! $actor->hasRole(PlatformRole::SuperAdmin->value))
                ->map(fn (PlatformRole $role) => ['key' => $role->value, 'label' => $role->label()])
                ->values(),
            'can' => ['manage' => $actor->can('assignRole', $user)],
            'password_confirmed' => time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800),
        ]);
    }

    public function updateRole(Request $request, User $user, AssignPlatformRole $action): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfNotPlatformStaff($user);

        Gate::authorize('assignRole', $user);

        /** @var array{role: string, reason: string} $validated */
        $validated = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', array_map(fn (PlatformRole $role) => $role->value, PlatformRole::cases()))],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->handle(
                subject: $user,
                actor: $actor,
                role: PlatformRole::from($validated['role']),
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['role' => $exception->getMessage()]);
        }

        return back()->with('success', __('access.staff.role_assigned'));
    }

    /**
     * @return array{public_id: string, name: string, email: string, role_label: string|null, requires_two_factor: bool, two_factor_enabled: bool, identity_status_label: string, identity_status_tone: string}
     */
    protected function summary(User $user): array
    {
        $role = collect(PlatformRole::cases())->first(fn (PlatformRole $role) => $user->hasRole($role->value));

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role_label' => $role?->label(),
            'requires_two_factor' => $role?->requiresTwoFactor() ?? false,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'identity_status_label' => $user->identity_status->label(),
            'identity_status_tone' => $user->identity_status->tone(),
        ];
    }

    protected function abortIfNotPlatformStaff(User $user): void
    {
        abort_if($user->accountMembership()->exists(), 404);
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
