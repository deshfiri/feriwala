<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Actions\AssignPlatformRole;
use App\Domain\Access\Actions\InvitePlatformStaff;
use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\Queries\PlatformStaffDirectory;
use App\Domain\Account\Actions\ChangeIdentityAccess;
use App\Domain\Account\Enums\UserStatus;
use App\Domain\Account\Policies\UserPolicy;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Platform staff management: viewing, inviting, role assignment, and
 * sign-in status changes (activate/suspend/deactivate) for the identities
 * {@see PlatformStaffDirectory} defines -- a `User` with no business
 * account membership at all (D23).
 *
 * Every safety guard lives in {@see InvitePlatformStaff},
 * {@see AssignPlatformRole}, {@see ChangeIdentityAccess} and
 * {@see UserPolicy}, not here -- this controller only validates the
 * request shape and turns an action's own refusal into a form error, the
 * same way {@see IdentityAccessController} already does for locking a
 * login.
 *
 * Sign-in status changes (activate/suspend/deactivate) share one
 * authorization ability, {@see UserPolicy::manageStaffAccess()}, rather
 * than a new one per status: the question ("may this actor change whose
 * sign-in status") and its two rules (nobody changes their own, only a
 * Super Admin touches a Super Admin) are identical whichever of the four
 * {@see UserStatus} states is the target. It is
 * deliberately not `UserPolicy::lock()` -- that ability governs a
 * *business owner's* identity, gated on `account.edit`, and reusing it here
 * would mean holding `access.edit` (Platform Staff management) accidentally
 * also granted the power to lock a business owner's login.
 */
class PlatformStaffController extends Controller
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

        return Inertia::render('admin/staff/index', [
            'staff' => $staff,
            'filters' => ['search' => $search],
            'can' => ['invite' => $this->actor($request)->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit))],
        ]);
    }

    public function create(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        return Inertia::render('admin/staff/create', [
            'roles' => $this->availableRoles($actor),
        ]);
    }

    public function store(Request $request, InvitePlatformStaff $action): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        /** @var array{name: string, email: string, mobile: ?string, roles: list<string>, reason: string} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'mobile' => ['nullable', 'string', 'max:20', Rule::unique(User::class, 'mobile')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->assignableRoleNames())],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $user = $action->handle(
                actor: $actor,
                name: $validated['name'],
                email: $validated['email'],
                mobile: $validated['mobile'] ?? null,
                roleNames: $validated['roles'],
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['roles' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.staff.show', $user->public_id)
            ->with('success', __('access.staff.invited'));
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorizeView($request);
        $this->abortIfNotPlatformStaff($user);

        $actor = $this->actor($request);

        return Inertia::render('admin/staff/show', [
            'staffMember' => $this->summary($user),
            'permissionGroups' => PermissionCatalogue::groupedByModule($user->getAllPermissions()->pluck('name')->all()),
            'roles' => $this->availableRoles($actor),
            'assignedRoles' => $user->getRoleNames()->values(),
            'can' => [
                'manageRoles' => $actor->can('assignRole', $user),
                'manageStatus' => $actor->can('manageStaffAccess', $user),
            ],
            'password_confirmed' => $this->passwordRecentlyConfirmed($request),
        ]);
    }

    public function updateRoles(Request $request, User $user, AssignPlatformRole $action): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfNotPlatformStaff($user);

        Gate::authorize('assignRole', $user);

        /** @var array{roles: list<string>, reason: string} $validated */
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($this->assignableRoleNames())],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $action->handle(
                subject: $user,
                actor: $actor,
                roleNames: $validated['roles'],
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['roles' => $exception->getMessage()]);
        }

        return back()->with('success', __('access.staff.roles_updated'));
    }

    public function resendInvite(Request $request, User $user, InvitePlatformStaff $action): RedirectResponse
    {
        $actor = $this->actor($request);
        $this->abortIfNotPlatformStaff($user);

        abort_unless($actor->can(PermissionCatalogue::name(PermissionModule::Access, PermissionAction::Edit)), 403);

        $action->sendInvite($user);

        return back()->with('success', __('access.staff.invite_resent'));
    }

    public function activate(Request $request, User $user, ChangeIdentityAccess $action): RedirectResponse
    {
        return $this->applyStatusChange($request, $user, function (string $reason) use ($request, $user, $action) {
            $user->identity_status->isTerminal()
                ? throw new InvalidArgumentException(__('access.staff.cannot_reactivate_closed'))
                : $action->unlock($user, $this->actor($request), $reason);
        }, __('access.staff.activated'));
    }

    public function suspend(Request $request, User $user, ChangeIdentityAccess $action): RedirectResponse
    {
        return $this->applyStatusChange($request, $user, fn (string $reason) => $action->suspend($user, $this->actor($request), $reason), __('access.staff.suspended'));
    }

    public function deactivate(Request $request, User $user, ChangeIdentityAccess $action): RedirectResponse
    {
        return $this->applyStatusChange($request, $user, fn (string $reason) => $action->deactivate($user, $this->actor($request), $reason), __('access.staff.deactivated'));
    }

    /**
     * @param  callable(string): void  $change
     */
    protected function applyStatusChange(Request $request, User $user, callable $change, string $message): RedirectResponse
    {
        $this->abortIfNotPlatformStaff($user);

        Gate::authorize('manageStaffAccess', $user);

        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        try {
            $change($validated['reason']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    protected function availableRoles(User $actor): array
    {
        return collect(PlatformRole::cases())
            /*
             * Super Admin is offered only to an actor who already holds it
             * -- granting it is how a lesser role would make itself
             * unrestricted, so AssignPlatformRole refuses this at the
             * point of assignment regardless; leaving it out of the list
             * here is the UI half of the same rule, not the guard itself.
             */
            ->reject(fn (PlatformRole $role) => $role->grantsEverything() && ! $actor->hasRole(PlatformRole::SuperAdmin->value))
            ->map(fn (PlatformRole $role) => ['key' => $role->value, 'label' => $role->label()])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    protected function assignableRoleNames(): array
    {
        return array_map(fn (PlatformRole $role) => $role->value, PlatformRole::cases());
    }

    /**
     * @return array{public_id: string, name: string, email: string, mobile: string|null, role_label: string|null, requires_two_factor: bool, two_factor_enabled: bool, identity_status: string, identity_status_label: string, identity_status_tone: string, last_login_at: string|null}
     */
    protected function summary(User $user): array
    {
        $role = collect(PlatformRole::cases())->first(fn (PlatformRole $role) => $user->hasRole($role->value));

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'role_label' => $role?->label(),
            'requires_two_factor' => $role?->requiresTwoFactor() ?? false,
            'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
            'identity_status' => $user->identity_status->value,
            'identity_status_label' => $user->identity_status->label(),
            'identity_status_tone' => $user->identity_status->tone(),
            'last_login_at' => $user->authenticatedSessions()->latest('id')->value('created_at')?->toIso8601String(),
        ];
    }

    protected function abortIfNotPlatformStaff(User $user): void
    {
        abort_if($user->accountMembership()->exists(), 404);
    }

    protected function passwordRecentlyConfirmed(Request $request): bool
    {
        return time() - (int) $request->session()->get('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
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
