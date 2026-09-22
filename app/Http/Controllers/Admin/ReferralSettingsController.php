<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Package\Models\Package;
use App\Domain\Referral\Actions\CloseReferralPlan;
use App\Domain\Referral\Actions\OpenReferralPlan;
use App\Domain\Referral\Data\ReferralPlanDraft;
use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\CommissionBase;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Enums\RewardType;
use App\Domain\Referral\Exceptions\ReferralRefused;
use App\Domain\Referral\Models\ReferralPlan;
use App\Domain\Referral\Models\ReferralPlanLevel;
use App\Domain\Referral\ReferralSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Money;
use App\Support\Money\Rules\DecimalAmountRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The multi-level referral configuration (§25.4.1, D24, P7-12, P7-44).
 *
 * The global switch, and plan versions: opened from a date with a rule for
 * every level up to the depth, closed with a reason, never edited. Seeing it
 * needs `referral.view_settings`; changing any of it needs
 * `referral.manage_settings`. Every change is audited by the action that makes
 * it.
 */
class ReferralSettingsController extends Controller
{
    public function index(Request $request, ReferralSettings $settings): Response
    {
        Gate::authorize(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::ViewSettings));

        $now = CarbonImmutable::now();

        $plans = ReferralPlan::query()
            ->with(['levels', 'package:id,public_id,name', 'openedBy:id,name', 'closedBy:id,name'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(10)
            ->withQueryString();

        $packages = Package::query()->orderBy('name')->get(['id', 'public_id', 'name']);
        $packageNames = $packages->pluck('name', 'id');

        return Inertia::render('admin/referral-settings', [
            'enabled' => $settings->enabled(),
            'can_manage' => $request->user()?->can(PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::ManageSettings)) ?? false,
            'plans' => $plans->through(fn (ReferralPlan $plan) => [
                'id' => $plan->public_id,
                'package' => $plan->package?->name,
                'trigger' => $plan->trigger_event->value,
                'trigger_label' => __('referral.triggers.'.$plan->trigger_event->value),
                'base' => $plan->commission_base->value,
                'base_label' => __('referral.bases.'.$plan->commission_base->value),
                'max_depth' => $plan->max_depth,
                'levels' => $plan->levels->map(fn (ReferralPlanLevel $level) => [
                    'level' => $level->level,
                    'reward' => $this->describe($level->rule()),
                    'enabled' => $level->is_enabled,
                    'required_packages' => array_values(array_filter(array_map(
                        fn (int $id) => $packageNames[$id] ?? null,
                        $level->requiredPackageIds(),
                    ))),
                    'min_active_direct_referrals' => $level->min_active_direct_referrals,
                ])->all(),
                'joining_reward' => ($rule = $plan->joiningRule()) === null ? null : $this->describe($rule),
                'holding_days' => $plan->holding_days,
                'minimum_qualifying_payment' => Money::of($plan->minimum_qualifying_payment_minor, Currency::from($plan->currency_code))->jsonSerialize(),
                'qualifies' => [
                    'suspended' => $plan->qualifies_suspended,
                    'restricted' => $plan->qualifies_restricted,
                    'package_lapsed' => $plan->qualifies_package_lapsed,
                    'not_active' => $plan->qualifies_not_active,
                ],
                'state' => match (true) {
                    $plan->isClosed() => 'closed',
                    $plan->effective_from->greaterThan($now) => 'scheduled',
                    $plan->isInForceAt($now) => 'in_force',
                    default => 'ended',
                },
                'effective_from' => $plan->effective_from->toIso8601String(),
                'effective_to' => $plan->effective_to?->toIso8601String(),
                'reason' => $plan->reason,
                'opened_by' => $plan->openedBy->name,
                'close_reason' => $plan->close_reason,
                'closed_by' => $plan->closedBy?->name,
            ]),
            'packages' => $packages->map(fn (Package $package) => [
                'value' => $package->public_id,
                'label' => $package->name,
            ])->all(),
            'triggers' => array_map(fn (ReferralTrigger $trigger) => [
                'value' => $trigger->value,
                'label' => __('referral.triggers.'.$trigger->value),
            ], ReferralTrigger::cases()),
            'bases' => array_map(fn (CommissionBase $base) => [
                'value' => $base->value,
                'label' => __('referral.bases.'.$base->value),
            ], CommissionBase::cases()),
        ]);
    }

    public function toggle(Request $request, ReferralSettings $settings): RedirectResponse
    {
        Gate::authorize($this->manage());

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $settings->switchTo((bool) $validated['enabled'], $this->person($request), $validated['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __((bool) $validated['enabled'] ? 'referral.flash.switched_on' : 'referral.flash.switched_off')]);

        return to_route('admin.referral-settings.index');
    }

    public function store(Request $request, OpenReferralPlan $open): RedirectResponse
    {
        Gate::authorize($this->manage());

        $validated = $request->validate([
            'package' => ['nullable', 'string', 'size:26'],
            'trigger' => ['required', Rule::in(ReferralTrigger::values())],
            'commission_base' => ['required', Rule::in(CommissionBase::values())],
            'max_depth' => ['required', 'integer', 'min:1', 'max:100'],
            'levels' => ['required', 'array', 'min:1', 'max:100'],
            'levels.*.type' => ['required', Rule::in(RewardType::values())],
            'levels.*.amount' => ['nullable', new DecimalAmountRule],
            'levels.*.rate_percent' => ['nullable', 'string', 'max:6'],
            'levels.*.cap' => ['nullable', new DecimalAmountRule],
            'levels.*.enabled' => ['sometimes', 'boolean'],
            'levels.*.required_packages' => ['nullable', 'array'],
            'levels.*.required_packages.*' => ['string', 'size:26'],
            'levels.*.min_active_direct_referrals' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'joining_type' => ['nullable', Rule::in(RewardType::values())],
            'joining_amount' => ['nullable', new DecimalAmountRule],
            'joining_rate_percent' => ['nullable', 'string', 'max:6'],
            'joining_cap' => ['nullable', new DecimalAmountRule],
            'holding_days' => ['required', 'integer', 'min:0', 'max:365'],
            'minimum_qualifying_payment' => ['required', new DecimalAmountRule],
            'qualifies_suspended' => ['sometimes', 'boolean'],
            'qualifies_restricted' => ['sometimes', 'boolean'],
            'qualifies_package_lapsed' => ['sometimes', 'boolean'],
            'qualifies_not_active' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $effectiveFrom = CarbonImmutable::parse($validated['effective_from']);

        // A version cannot reach back: the events before it were already
        // decided under the version in force then.
        if ($effectiveFrom->lessThan(CarbonImmutable::now()->subMinutes(5))) {
            throw ValidationException::withMessages(['effective_from' => __('referral.refused.effective_in_past')]);
        }

        $packagesByPublicId = Package::query()->pluck('id', 'public_id');

        $package = $validated['package'] ?? null;

        if ($package !== null && ! isset($packagesByPublicId[$package])) {
            throw ValidationException::withMessages(['package' => __('referral.refused.package_not_found')]);
        }

        $levels = [];

        foreach (array_values($validated['levels']) as $index => $level) {
            $levels[] = [
                'level' => $index + 1,
                'rule' => $this->rule(
                    $level['type'],
                    $level['amount'] ?? null,
                    $level['rate_percent'] ?? null,
                    $level['cap'] ?? null,
                    'levels.'.$index,
                    $index + 1,
                ),
                'enabled' => (bool) ($level['enabled'] ?? false),
                'required_package_ids' => array_values(array_map(
                    fn (string $publicId) => (int) ($packagesByPublicId[$publicId] ?? throw ValidationException::withMessages([
                        'levels.'.$index.'.required_packages' => __('referral.refused.package_not_found'),
                    ])),
                    $level['required_packages'] ?? [],
                )),
                'min_active_direct_referrals' => (int) ($level['min_active_direct_referrals'] ?? 0),
            ];
        }

        $joiningType = $validated['joining_type'] ?? null;

        $draft = new ReferralPlanDraft(
            packageId: $package === null ? null : (int) $packagesByPublicId[$package],
            trigger: ReferralTrigger::from($validated['trigger']),
            base: CommissionBase::from($validated['commission_base']),
            maxDepth: (int) $validated['max_depth'],
            levels: $levels,
            joiningReward: $joiningType === null ? null : $this->rule(
                $joiningType,
                $validated['joining_amount'] ?? null,
                $validated['joining_rate_percent'] ?? null,
                $validated['joining_cap'] ?? null,
                'joining',
                0,
            ),
            holdingDays: (int) $validated['holding_days'],
            minimumQualifyingPaymentMinor: DecimalAmount::parse($validated['minimum_qualifying_payment'])->minorUnits,
            qualifiesSuspended: (bool) ($validated['qualifies_suspended'] ?? false),
            qualifiesRestricted: (bool) ($validated['qualifies_restricted'] ?? false),
            qualifiesPackageLapsed: (bool) ($validated['qualifies_package_lapsed'] ?? false),
            qualifiesNotActive: (bool) ($validated['qualifies_not_active'] ?? false),
            effectiveFrom: $effectiveFrom,
            reason: $validated['reason'],
        );

        try {
            $open->handle($draft, $this->person($request));
        } catch (ReferralRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('referral.flash.plan_opened')]);

        return to_route('admin.referral-settings.index');
    }

    public function close(Request $request, string $plan, CloseReferralPlan $close): RedirectResponse
    {
        Gate::authorize($this->manage());

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        /** @var ReferralPlan|null $record */
        $record = ReferralPlan::query()->where('public_id', $plan)->first();

        abort_if($record === null, 404);

        try {
            $close->handle($record, $this->person($request), $validated['reason']);
        } catch (ReferralRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('referral.flash.plan_closed')]);

        return to_route('admin.referral-settings.index');
    }

    /**
     * A rule from the form, or a refusal naming the field it came from.
     */
    protected function rule(string $type, ?string $amount, ?string $ratePercent, ?string $cap, string $field, int $level): RewardRule
    {
        $type = RewardType::from($type);
        $rateBps = $type === RewardType::Percentage && $ratePercent !== null
            ? RewardRule::basisPointsFromPercent($ratePercent)
            : null;

        try {
            return new RewardRule(
                $type,
                $type === RewardType::Fixed ? DecimalAmount::parseOrNull($amount)?->minorUnits : null,
                $rateBps,
                DecimalAmount::parseOrNull($cap)?->minorUnits,
            );
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('referral.refused.level_invalid', ['level' => $level])]);
        }
    }

    /**
     * @return array{type: string, amount: array<string, mixed>|null, percent: string|null, cap: array<string, mixed>|null}
     */
    protected function describe(RewardRule $rule): array
    {
        return [
            'type' => $rule->type->value,
            'amount' => $rule->amountMinor === null ? null : Money::of($rule->amountMinor, Currency::BDT)->jsonSerialize(),
            'percent' => $rule->rateBps === null ? null : rtrim(rtrim(sprintf('%d.%02d', intdiv($rule->rateBps, 100), $rule->rateBps % 100), '0'), '.'),
            'cap' => $rule->capMinor === null ? null : Money::of($rule->capMinor, Currency::BDT)->jsonSerialize(),
        ];
    }

    protected function manage(): string
    {
        return PermissionCatalogue::name(PermissionModule::Referral, PermissionAction::ManageSettings);
    }

    protected function person(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
