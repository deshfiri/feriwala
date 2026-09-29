<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Actions\SetSupplierWithdrawalLimits;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Domain\Withdrawal\AccountWithdrawalLimits;
use App\Domain\Withdrawal\Actions\SetAccountWithdrawalLimits;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform-default withdrawal limits, and a per-owner override on either
 * side (§27, D25) -- the settings screen `AccountWithdrawalLimits` and
 * `SupplierWithdrawalLimits` have always read from, never previously wired
 * to an admin screen on the Client/Partner side and never wired to one at
 * all on the Supplier side.
 *
 * Gated on `withdrawal.manage_settings`, which no seeded role holds today
 * (`.ai/rules`'s own separation of duties: `WithdrawalApprover` holds every
 * other withdrawal permission but deliberately not this one) -- reachable by
 * Super Admin's own override until an administrator decides who else should
 * hold it.
 */
class WithdrawalLimitsController extends Controller
{
    public function __construct(
        protected SettingsRepository $settings,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($this->canView($actor), 403);

        return Inertia::render('admin/withdrawal-limits', [
            'account' => [
                'minimum' => $this->settings->get(AccountWithdrawalLimits::MINIMUM_SETTING) ?? AccountWithdrawalLimits::DEFAULT_MINIMUM,
                'maximum' => $this->settings->get(AccountWithdrawalLimits::MAXIMUM_SETTING),
            ],
            'supplier' => [
                'minimum' => $this->settings->get(SupplierWithdrawalLimits::MINIMUM_SETTING) ?? SupplierWithdrawalLimits::DEFAULT_MINIMUM,
                'maximum' => $this->settings->get(SupplierWithdrawalLimits::MAXIMUM_SETTING),
            ],
            'can' => ['manage' => $this->canManage($actor)],
        ]);
    }

    public function updateAccountDefault(Request $request, SetAccountWithdrawalLimits $action): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $this->validateLimits($request);

        $action->setDefault($actor, $validated['minimum'], $validated['maximum']);

        return back()->with('success', __('withdrawal.admin.limits_updated'));
    }

    public function updateSupplierDefault(Request $request, SetSupplierWithdrawalLimits $action): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $this->validateLimits($request);

        $action->setDefault($actor, $validated['minimum'], $validated['maximum']);

        return back()->with('success', __('withdrawal.admin.limits_updated'));
    }

    public function updateAccountOverride(Request $request, SetAccountWithdrawalLimits $action): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $this->validateOverride($request);

        /** @var BusinessAccount $account */
        $account = BusinessAccount::query()->where('public_id', $validated['owner'])->firstOrFail();

        $action->setOverride($actor, $account, $validated['minimum'], $validated['maximum']);

        return back()->with('success', __('withdrawal.admin.override_updated'));
    }

    public function updateSupplierOverride(Request $request, SetSupplierWithdrawalLimits $action): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $this->validateOverride($request);

        /** @var Supplier $supplier */
        $supplier = Supplier::query()->where('public_id', $validated['owner'])->firstOrFail();

        $action->setOverride($actor, $supplier, $validated['minimum'], $validated['maximum']);

        return back()->with('success', __('withdrawal.admin.override_updated'));
    }

    /**
     * @return array{minimum: ?string, maximum: ?string}
     */
    protected function validateLimits(Request $request): array
    {
        $validated = $request->validate([
            'minimum' => ['nullable', new DecimalAmountRule(Currency::BDT)],
            'maximum' => ['nullable', new DecimalAmountRule(Currency::BDT)],
        ]);

        $this->assertMinimumNotAboveMaximum($validated);

        return [
            'minimum' => $validated['minimum'] ?? null,
            'maximum' => $validated['maximum'] ?? null,
        ];
    }

    /**
     * @return array{owner: string, minimum: ?string, maximum: ?string}
     */
    protected function validateOverride(Request $request): array
    {
        $validated = $request->validate([
            'owner' => ['required', 'string'],
            'minimum' => ['nullable', new DecimalAmountRule(Currency::BDT)],
            'maximum' => ['nullable', new DecimalAmountRule(Currency::BDT)],
        ]);

        $this->assertMinimumNotAboveMaximum($validated);

        return [
            'owner' => $validated['owner'],
            'minimum' => $validated['minimum'] ?? null,
            'maximum' => $validated['maximum'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function assertMinimumNotAboveMaximum(array $validated): void
    {
        $minimum = $validated['minimum'] ?? null;
        $maximum = $validated['maximum'] ?? null;

        if ($minimum !== null && $maximum !== null && bccomp($minimum, $maximum, 2) > 0) {
            throw ValidationException::withMessages([
                'minimum' => __('withdrawal.admin.minimum_above_maximum'),
            ]);
        }
    }

    protected function canView(User $actor): bool
    {
        return $actor->can($this->permission(PermissionAction::View))
            || $this->canManage($actor);
    }

    protected function canManage(User $actor): bool
    {
        return $actor->can($this->permission(PermissionAction::ManageSettings));
    }

    protected function permission(PermissionAction $action): string
    {
        return PermissionCatalogue::name(PermissionModule::Withdrawal, $action);
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
