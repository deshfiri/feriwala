<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Package\Models\Package;
use App\Domain\Wallet\Actions\ManageDepositRules;
use App\Domain\Wallet\Enums\DepositFrequency;
use App\Domain\Wallet\Enums\DepositRefundability;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\DepositRuleChange;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * What accounts are required to deposit and keep (§24.1).
 *
 * The screen where a deposit policy is written, and — just as importantly —
 * where somebody can see which rule wins for whom. §24.1 allows six scopes and
 * "most specific wins" is easy to say and hard to trust, so the list shows the
 * scope beside every rule and orders them the way the resolver does.
 *
 * Nothing here edits a figure. Raising a minimum balance closes one rule and
 * opens another, because an account restricted in March was restricted against
 * the rule of March and that has to stay answerable. The history beside each
 * rule is what makes that visible rather than merely true.
 */
class DepositRuleController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(
        protected ManageDepositRules $rules,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->actor($request);

        abort_unless($this->canView($actor), 403);

        $rules = DepositRule::query()
            ->with('createdBy:id,name')
            // The resolver's own order: most specific first, then priority, then
            // the most recent. A list that sorted by id would not show what an
            // account is actually held to.
            ->orderByDesc('is_active')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DepositRule $rule) => $this->summary($rule));

        return Inertia::render('admin/deposit-rules', [
            'rules' => $rules,
            'scopes' => array_map(fn (RuleScope $scope) => [
                'value' => $scope->value,
                'label' => $scope->label(),
                'specificity' => $scope->specificity(),
            ], RuleScope::cases()),
            'frequencies' => array_map(fn (DepositFrequency $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ], DepositFrequency::cases()),
            'refundabilities' => array_map(fn (DepositRefundability $case) => [
                'value' => $case->value,
                'label' => $case->label(),
            ], DepositRefundability::cases()),
            'packages' => Package::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Package $package) => [
                    'value' => $package->id,
                    'label' => $package->name,
                ])
                ->all(),
            'history' => DepositRuleChange::query()
                ->with('actor:id,name')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (DepositRuleChange $change) => [
                    'id' => $change->id,
                    'rule_id' => $change->deposit_rule_id,
                    'action' => $change->action,
                    'actor' => $change->actor?->name,
                    'reason' => $change->reason,
                    'before' => $change->before,
                    'after' => $change->after,
                    'effective_from' => $change->effective_from->toIso8601String(),
                    'at' => $change->created_at->toIso8601String(),
                ])
                ->all(),
            'can' => ['manage' => $this->canManage($actor)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $request->validate([
            'scope' => ['required', Rule::enum(RuleScope::class)],
            'scope_id' => ['nullable', 'integer'],

            // Entered in Taka by the administrator; converted to minor units below.
            'required_deposit' => ['required', new DecimalAmountRule],
            'minimum_balance' => ['required', new DecimalAmountRule],
            'required_top_up_minor' => ['nullable', 'integer', 'min:0'],
            'low_threshold' => ['nullable', new DecimalAmountRule],
            'critical_balance_threshold_minor' => ['nullable', 'integer', 'min:0'],

            'deposit_deadline_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'grace_period_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'frequency' => ['required', Rule::enum(DepositFrequency::class)],
            'frequency_days' => ['nullable', 'integer', 'min:1', 'max:3650'],

            'restricts_chargeable_services' => ['boolean'],
            'pauses_website_setup' => ['boolean'],
            'disables_website' => ['boolean'],
            'restricts_account' => ['boolean'],
            'disables_account' => ['boolean'],
            'restores_automatically' => ['boolean'],

            'refundability' => ['required', Rule::enum(DepositRefundability::class)],
            'refundable_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'reserved_until_cancellation' => ['boolean'],
            'deposit_usable_for_charges' => ['boolean'],
            'withdrawable_after_liabilities' => ['boolean'],

            'priority' => ['nullable', 'integer'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->rules->create(
                actor: $actor,
                scope: RuleScope::from($validated['scope']),
                scopeId: $validated['scope_id'] ?? null,
                attributes: $this->attributes($validated),
                effectiveFrom: CarbonImmutable::parse($validated['effective_from']),
                effectiveUntil: isset($validated['effective_until'])
                    ? CarbonImmutable::parse($validated['effective_until'])
                    : null,
                reason: $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            // An overlapping window is an answer to the request, not an error
            // page: the administrator has to close the open rule first.
            return back()->withErrors(['effective_from' => $exception->getMessage()]);
        }

        return back()->with('success', __('wallet.rules.created'));
    }

    public function close(Request $request, string $rule): RedirectResponse
    {
        $actor = $this->actor($request);

        abort_unless($this->canManage($actor), 403);

        $validated = $request->validate([
            'effective_until' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $record = DepositRule::query()->where('public_id', $rule)->firstOrFail();

        try {
            $this->rules->close(
                $actor,
                $record,
                CarbonImmutable::parse($validated['effective_until']),
                $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['effective_until' => $exception->getMessage()]);
        }

        return back()->with('success', __('wallet.rules.closed'));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributes(array $validated): array
    {
        return [
            'required_initial_deposit_minor' => DecimalAmount::parse($validated['required_deposit']),
            'minimum_balance_minor' => DecimalAmount::parse($validated['minimum_balance']),
            'required_top_up_minor' => $validated['required_top_up_minor'] ?? 0,
            'low_balance_threshold_minor' => DecimalAmount::parseOrNull($validated['low_threshold'] ?? null),
            'critical_balance_threshold_minor' => $validated['critical_balance_threshold_minor'] ?? null,
            'deposit_deadline_days' => $validated['deposit_deadline_days'] ?? null,
            'grace_period_days' => $validated['grace_period_days'] ?? null,
            'frequency' => $validated['frequency'],
            'frequency_days' => $validated['frequency_days'] ?? null,
            'restricts_chargeable_services' => $validated['restricts_chargeable_services'] ?? false,
            'pauses_website_setup' => $validated['pauses_website_setup'] ?? false,
            'disables_website' => $validated['disables_website'] ?? false,
            'restricts_account' => $validated['restricts_account'] ?? false,
            'disables_account' => $validated['disables_account'] ?? false,
            'restores_automatically' => $validated['restores_automatically'] ?? true,
            'refundability' => $validated['refundability'],
            'refundable_percent' => $validated['refundable_percent'] ?? null,
            'reserved_until_cancellation' => $validated['reserved_until_cancellation'] ?? false,
            'deposit_usable_for_charges' => $validated['deposit_usable_for_charges'] ?? true,
            'withdrawable_after_liabilities' => $validated['withdrawable_after_liabilities'] ?? true,
            'priority' => $validated['priority'] ?? 0,
            'note' => $validated['note'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(DepositRule $rule): array
    {
        return [
            'id' => $rule->public_id,
            'scope' => $rule->scope->value,
            'scope_label' => $rule->scope->label(),
            'scope_id' => $rule->scope_id,

            // Higher wins. Shown because "most specific wins" is easy to say and
            // hard to trust without seeing the order.
            'specificity' => $rule->scope->specificity(),

            'required_deposit' => $rule->required_initial_deposit_minor->jsonSerialize(),
            'minimum_balance' => $rule->minimum_balance_minor->jsonSerialize(),
            'required_top_up' => $rule->required_top_up_minor->jsonSerialize(),
            'low_threshold' => $rule->low_balance_threshold_minor?->jsonSerialize(),
            'critical_threshold' => $rule->critical_balance_threshold_minor?->jsonSerialize(),
            'grace_period_days' => $rule->grace_period_days,
            'deposit_deadline_days' => $rule->deposit_deadline_days,
            'frequency' => $rule->frequency->value,
            'frequency_label' => $rule->frequency->label(),
            'refundability' => $rule->refundability->value,
            'refundability_label' => $rule->refundability->label(),
            'refundable_percent' => $rule->refundable_percent,
            'reserved_until_cancellation' => $rule->reserved_until_cancellation,
            'deposit_usable_for_charges' => $rule->deposit_usable_for_charges,
            'withdrawable_after_liabilities' => $rule->withdrawable_after_liabilities,
            'actions' => [
                'restricts_chargeable_services' => $rule->restricts_chargeable_services,
                'pauses_website_setup' => $rule->pauses_website_setup,
                'disables_website' => $rule->disables_website,
                'restricts_account' => $rule->restricts_account,
                'disables_account' => $rule->disables_account,
                'restores_automatically' => $rule->restores_automatically,
            ],
            'priority' => $rule->priority,
            'effective_from' => $rule->effective_from->toIso8601String(),
            'effective_until' => $rule->effective_until?->toIso8601String(),
            'is_active' => $rule->is_active,
            'note' => $rule->note,
            'created_by' => $rule->createdBy?->name,
        ];
    }

    protected function canView(User $actor): bool
    {
        return $actor->can(PermissionCatalogue::name(
            PermissionModule::Wallet,
            PermissionAction::View,
        ));
    }

    protected function canManage(User $actor): bool
    {
        return $actor->can(PermissionCatalogue::name(
            PermissionModule::Wallet,
            PermissionAction::ManageSettings,
        ));
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if(! $actor instanceof User, 403);

        return $actor;
    }
}
