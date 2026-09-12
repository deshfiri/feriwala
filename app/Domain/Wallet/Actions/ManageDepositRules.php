<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Wallet\Models\DepositRule;
use App\Domain\Wallet\Models\DepositRuleChange;
use App\Models\User;
use App\Support\Rules\RuleScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Adding and closing deposit rules (§24.1).
 *
 * **A requirement is never edited.** Raising a minimum balance opens a new rule
 * and closes the one it replaces, so an obligation captured last month still
 * resolves to the rule it was captured from, and "when did this go up" has an
 * answer. Editing in place would rewrite the reason an account was restricted in
 * March.
 *
 * Overlap is refused rather than resolved. Two rules in force for the same
 * subject at the same moment would make the requirement depend on which row the
 * resolver read first — deterministic, thanks to the tie-break, but not
 * something anybody chose. Checked at the rule's own level of specificity: a
 * package rule may overlap the global one, because that is what "more specific"
 * means.
 *
 * Every change writes two records in the same transaction: the general audit log
 * says somebody acted, and {@see DepositRuleChange} says what the figures were
 * before and after. The second is the one that answers a question about money
 * months later.
 */
class ManageDepositRules
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(
        User $actor,
        RuleScope $scope,
        ?int $scopeId,
        array $attributes,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
        ?string $reason = null,
    ): DepositRule {
        if ($scope->isGlobal() === ($scopeId !== null)) {
            throw new InvalidArgumentException(
                'A global rule applies to nobody in particular; every other scope applies to exactly one record.'
            );
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            throw new InvalidArgumentException('A deposit rule must end after it begins.');
        }

        return $this->database->transaction(function () use (
            $actor, $scope, $scopeId, $attributes, $effectiveFrom, $effectiveUntil, $reason
        ) {
            $this->refuseOverlap($scope, $scopeId, $effectiveFrom, $effectiveUntil);

            $rule = DepositRule::create([
                ...$attributes,
                'scope' => $scope,
                'scope_id' => $scopeId,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
                'created_by' => $actor->id,
            ]);

            $this->record($rule, DepositRuleChange::CREATED, null, $actor, $reason);

            return $rule;
        });
    }

    /**
     * End a rule's window without deleting it (§24.1, §36.2).
     *
     * A rule an account was held to is part of that account's story. Closing it
     * stops it applying from a date; deleting it would make the restriction it
     * caused unexplainable.
     */
    public function close(
        User $actor,
        DepositRule $rule,
        CarbonImmutable $effectiveUntil,
        ?string $reason = null,
    ): DepositRule {
        if (! $effectiveUntil->isAfter($rule->effective_from)) {
            throw new InvalidArgumentException('A deposit rule cannot end before it begins.');
        }

        return $this->database->transaction(function () use ($actor, $rule, $effectiveUntil, $reason) {
            $before = $this->snapshot($rule);

            $rule->forceFill(['effective_until' => $effectiveUntil])->save();

            $this->record($rule, DepositRuleChange::CLOSED, $before, $actor, $reason);

            return $rule;
        });
    }

    /**
     * Switch a rule off outright.
     *
     * Distinct from closing it: closing says "this stopped applying on the 3rd",
     * deactivating says "this should never have applied". Both keep the row.
     */
    public function deactivate(User $actor, DepositRule $rule, ?string $reason = null): DepositRule
    {
        return $this->database->transaction(function () use ($actor, $rule, $reason) {
            $before = $this->snapshot($rule);

            $rule->forceFill(['is_active' => false])->save();

            $this->record($rule, DepositRuleChange::DEACTIVATED, $before, $actor, $reason);

            return $rule;
        });
    }

    /**
     * Refuse a window that overlaps one already open for the same subject.
     *
     * @throws InvalidArgumentException
     */
    protected function refuseOverlap(
        RuleScope $scope,
        ?int $scopeId,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): void {
        $clashes = DepositRule::query()
            ->where('scope', $scope)
            ->where(fn ($query) => $scopeId === null
                ? $query->whereNull('scope_id')
                : $query->where('scope_id', $scopeId))
            ->where('is_active', true)
            // Overlap in the usual sense: each window starts before the other
            // ends. An open-ended rule ends at no time at all, so it clashes
            // with anything starting after it.
            ->where(fn ($query) => $until === null
                ? $query
                : $query->where('effective_from', '<', $until))
            ->where(fn ($query) => $query
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $from))
            ->exists();

        if ($clashes) {
            throw new InvalidArgumentException(
                'A deposit rule is already in force for that subject over part of this window. Close it first.'
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    protected function record(
        DepositRule $rule,
        string $action,
        ?array $before,
        User $actor,
        ?string $reason,
    ): DepositRuleChange {
        $after = $this->snapshot($rule);

        $this->audit->handle(new AuditEntry(
            action: 'wallet.deposit_rule_'.$action,
            actorId: $actor->id,
            auditableType: DepositRule::class,
            auditableId: $rule->id,
            before: $before,
            after: $after,
            reason: $reason,
            module: 'wallet',
            // Somebody changed what a business is required to hold. That is the
            // kind of decision a reconciliation asks about (§32.2).
            isSensitive: true,
        ));

        return DepositRuleChange::create([
            'deposit_rule_id' => $rule->id,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'effective_from' => $rule->effective_from,
            'effective_until' => $rule->effective_until,
            'created_at' => now(),
        ]);
    }

    /**
     * The figures, as they stand, in a shape that still reads in a year.
     *
     * A whole snapshot rather than a diff: a diff is only legible beside the row
     * it applies to, and the row will have moved on.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(DepositRule $rule): array
    {
        return [
            'scope' => $rule->scope->value,
            'scope_id' => $rule->scope_id,
            'required_initial_deposit_minor' => $rule->required_initial_deposit_minor->minorUnits,
            'minimum_balance_minor' => $rule->minimum_balance_minor->minorUnits,
            'required_top_up_minor' => $rule->required_top_up_minor->minorUnits,
            'currency_code' => $rule->currency_code,
            'deposit_deadline_days' => $rule->deposit_deadline_days,
            'grace_period_days' => $rule->grace_period_days,
            'frequency' => $rule->frequency->value,
            'frequency_days' => $rule->frequency_days,
            'low_balance_threshold_minor' => $rule->low_balance_threshold_minor?->minorUnits,
            'critical_balance_threshold_minor' => $rule->critical_balance_threshold_minor?->minorUnits,
            'restricts_chargeable_services' => $rule->restricts_chargeable_services,
            'pauses_website_setup' => $rule->pauses_website_setup,
            'disables_website' => $rule->disables_website,
            'restricts_account' => $rule->restricts_account,
            'disables_account' => $rule->disables_account,
            'restores_automatically' => $rule->restores_automatically,
            'priority' => $rule->priority,
            'effective_from' => $rule->effective_from->toIso8601String(),
            'effective_until' => $rule->effective_until?->toIso8601String(),
            'is_active' => $rule->is_active,
        ];
    }
}
