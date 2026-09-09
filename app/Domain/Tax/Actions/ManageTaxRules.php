<?php

namespace App\Domain\Tax\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Tax\Enums\TaxMode;
use App\Domain\Tax\Enums\TaxScope;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Writing and closing tax rates and tax rules (D19, §9).
 *
 * Rates are **versioned, never edited**. When VAT moves from 15% to 12% the old
 * row closes and a new one opens under the same code, because editing the
 * percentage in place would silently rewrite the arithmetic of every invoice
 * already issued against it.
 *
 * A rule may only name a code that **exists**. D19 forbids assuming a statutory
 * rate, so a rule pointing at a code nobody ever configured could only ever
 * charge nothing — and it would do so while looking, on the settings screen,
 * exactly like a rule that works. Refusing it at the point of writing is the
 * difference between a system that undercharges visibly and one that
 * undercharges silently.
 *
 * Overlap is refused rather than resolved, at both levels. Two rates open for
 * one code, or two rules of identical scope, value and priority, would make what
 * is charged depend on which row was read first.
 */
class ManageTaxRules
{
    /** 100% in basis points. */
    public const BASIS_POINTS_WHOLE = 10000;

    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function createRate(
        User $actor,
        string $code,
        string $name,
        int $basisPoints,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
    ): TaxRate {
        $code = mb_strtolower(trim($code));

        if ($code === '') {
            throw new InvalidArgumentException('A rate needs a code.');
        }

        if ($basisPoints < 0 || $basisPoints > self::BASIS_POINTS_WHOLE) {
            throw new InvalidArgumentException('A rate must be between 0% and 100%.');
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            throw new InvalidArgumentException('A rate must end after it begins.');
        }

        return $this->database->transaction(function () use (
            $actor, $code, $name, $basisPoints, $effectiveFrom, $effectiveUntil
        ) {
            $this->refuseRateOverlap($code, $effectiveFrom, $effectiveUntil);

            $rate = TaxRate::create([
                'code' => $code,
                'name' => $name,
                'rate_basis_points' => $basisPoints,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'tax.rate_created',
                actorId: $actor->id,
                auditableType: TaxRate::class,
                auditableId: $rate->id,
                after: [
                    'code' => $code,
                    'rate_basis_points' => $basisPoints,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_until' => $effectiveUntil?->toIso8601String(),
                ],
                module: 'payment',
                // The rate charged on every invoice is what a tax audit opens with.
                isSensitive: true,
            ));

            return $rate;
        });
    }

    /**
     * Close a rate from now, leaving the invoices it priced intact.
     */
    public function closeRate(User $actor, TaxRate $rate): TaxRate
    {
        return $this->database->transaction(function () use ($actor, $rate) {
            /** @var TaxRate $locked */
            $locked = TaxRate::query()->lockForUpdate()->findOrFail($rate->id);

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => false,
                // Dated as well as deactivated, so a charge resolved against an
                // earlier moment still finds the rate in force then.
                'effective_until' => $locked->effective_until ?? now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'tax.rate_closed',
                actorId: $actor->id,
                auditableType: TaxRate::class,
                auditableId: $locked->id,
                before: ['is_active' => true],
                after: ['is_active' => false],
                module: 'payment',
                isSensitive: true,
            ));

            $rate->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    public function createRule(
        User $actor,
        TaxScope $scope,
        ?string $scopeValue,
        string $taxCode,
        TaxMode $mode,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
        int $priority = 0,
        ?string $note = null,
    ): TaxRule {
        $taxCode = mb_strtolower(trim($taxCode));
        $scopeValue = $scope->requiresValue() ? trim((string) $scopeValue) : null;

        if ($scope->requiresValue() && $scopeValue === '') {
            throw new InvalidArgumentException('A targeted rule must say what it applies to.');
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            throw new InvalidArgumentException('A tax rule must end after it begins.');
        }

        /*
         * The code has to exist. A rule naming one that does not could only
         * charge nothing, and would sit on the settings screen looking like a
         * rule that works (D19).
         */
        if (! TaxRate::query()->where('code', $taxCode)->exists()) {
            throw new InvalidArgumentException('There is no rate with that code. Add the rate first.');
        }

        return $this->database->transaction(function () use (
            $actor, $scope, $scopeValue, $taxCode, $mode,
            $effectiveFrom, $effectiveUntil, $priority, $note
        ) {
            $this->refuseRuleOverlap($scope, $scopeValue, $priority, $effectiveFrom, $effectiveUntil);

            $rule = TaxRule::create([
                'scope' => $scope,
                'scope_value' => $scopeValue,
                'tax_code' => $taxCode,
                'mode' => $mode,
                'priority' => $priority,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
                'note' => $note,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'tax.rule_created',
                actorId: $actor->id,
                auditableType: TaxRule::class,
                auditableId: $rule->id,
                after: [
                    'scope' => $scope->value,
                    'scope_value' => $scopeValue,
                    'tax_code' => $taxCode,
                    'mode' => $mode->value,
                    'priority' => $priority,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_until' => $effectiveUntil?->toIso8601String(),
                ],
                reason: $note,
                module: 'payment',
                isSensitive: true,
            ));

            return $rule;
        });
    }

    /**
     * Close a rule from now, leaving what it taxed intact.
     */
    public function closeRule(User $actor, TaxRule $rule): TaxRule
    {
        return $this->database->transaction(function () use ($actor, $rule) {
            /** @var TaxRule $locked */
            $locked = TaxRule::query()->lockForUpdate()->findOrFail($rule->id);

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => false,
                'effective_until' => $locked->effective_until ?? now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'tax.rule_closed',
                actorId: $actor->id,
                auditableType: TaxRule::class,
                auditableId: $locked->id,
                before: ['is_active' => true],
                after: ['is_active' => false],
                module: 'payment',
                isSensitive: true,
            ));

            $rule->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * Refuse a second open window for the same code.
     */
    protected function refuseRateOverlap(
        string $code,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): void {
        $query = TaxRate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->lockForUpdate()
            // Two windows overlap unless one ends before the other begins. An
            // open end date means "for ever", which is what it says.
            ->where(fn ($inner) => $inner
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $from));

        if ($until !== null) {
            $query->where('effective_from', '<', $until);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                'That code already has a rate in force for that period. Close it first.'
            );
        }
    }

    /**
     * Refuse a rule that would tie with one already open.
     *
     * Same scope, same target, same priority and an overlapping window: nothing
     * separates them, so which one applies would come down to row order.
     */
    protected function refuseRuleOverlap(
        TaxScope $scope,
        ?string $scopeValue,
        int $priority,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): void {
        $query = TaxRule::query()
            ->where('scope', $scope->value)
            ->where('priority', $priority)
            ->where('is_active', true)
            ->lockForUpdate()
            ->where(fn ($inner) => $inner
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $from));

        $scopeValue === null
            ? $query->whereNull('scope_value')
            : $query->whereRaw('lower(scope_value) = ?', [mb_strtolower($scopeValue)]);

        if ($until !== null) {
            $query->where('effective_from', '<', $until);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                'A tax rule of the same specificity is already in force for that period. Close it first.'
            );
        }
    }
}
