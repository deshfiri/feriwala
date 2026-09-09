<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Adding and closing fee rules (§9).
 *
 * **A price is never edited.** Changing what the registration fee costs opens a
 * new rule and closes the one it replaces, so every past quote can still be
 * reproduced and "when did this go up" has an answer. Editing a rule in place
 * would rewrite history that invoices and payments already reference.
 *
 * Overlap is refused rather than resolved. Two rules in force for the same fee
 * at the same level would make the price depend on which row the resolver
 * happened to read first — predictable, but not something anybody chose. The
 * new rule's window is checked against what is already open at its own level of
 * specificity: a package rule may overlap the global one, because that is what
 * "more specific" means.
 *
 * Closing a rule sets its end date rather than deleting it. A rule a payment was
 * priced against is part of that payment's story (§36.2).
 */
class ManageFeeRules
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function create(
        User $actor,
        FeeType $type,
        Money $amount,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
        ?int $packageId = null,
        ?string $note = null,
    ): FeeRule {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException('A fee cannot be negative.');
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            throw new InvalidArgumentException('A fee rule must end after it begins.');
        }

        return $this->database->transaction(function () use (
            $actor, $type, $amount, $effectiveFrom, $effectiveUntil, $packageId, $note
        ) {
            $this->refuseOverlap($type, $packageId, $effectiveFrom, $effectiveUntil);

            $rule = FeeRule::create([
                'fee_type' => $type,
                'package_id' => $packageId,
                'amount_minor' => $amount,
                'currency_code' => $amount->currency->value,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'billing.fee_rule_created',
                actorId: $actor->id,
                auditableType: FeeRule::class,
                auditableId: $rule->id,
                after: [
                    'fee_type' => $type->value,
                    'package_id' => $packageId,
                    'amount_minor' => $amount->minorUnits,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_until' => $effectiveUntil?->toIso8601String(),
                ],
                reason: $note,
                module: 'payment',
                // What Feriwala charges is exactly what a reconciliation goes
                // looking for when the numbers stop adding up.
                isSensitive: true,
            ));

            return $rule;
        });
    }

    /**
     * Close a rule from now, leaving what it priced intact.
     */
    public function close(User $actor, FeeRule $rule): FeeRule
    {
        return $this->database->transaction(function () use ($actor, $rule) {
            /** @var FeeRule $locked */
            $locked = FeeRule::query()->lockForUpdate()->findOrFail($rule->id);

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => false,
                // Dated as well as deactivated, so a quote resolved against an
                // earlier moment still finds it in force then.
                'effective_until' => $locked->effective_until ?? now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'billing.fee_rule_closed',
                actorId: $actor->id,
                auditableType: FeeRule::class,
                auditableId: $locked->id,
                before: ['is_active' => true],
                after: ['is_active' => false, 'effective_until' => $locked->effective_until?->toIso8601String()],
                module: 'payment',
                isSensitive: true,
            ));

            $rule->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * Refuse a window that overlaps one already open at the same level.
     */
    protected function refuseOverlap(
        FeeType $type,
        ?int $packageId,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): void {
        $query = FeeRule::query()
            ->where('fee_type', $type->value)
            ->where('is_active', true)
            ->lockForUpdate();

        $packageId === null
            ? $query->whereNull('package_id')
            : $query->where('package_id', $packageId);

        // Two windows overlap unless one ends before the other begins. An open
        // end date is treated as "for ever", which is what it means.
        $query->where(fn ($inner) => $inner
            ->whereNull('effective_until')
            ->orWhere('effective_until', '>', $from));

        if ($until !== null) {
            $query->where('effective_from', '<', $until);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                'A fee rule is already in force for that period. Close it first.'
            );
        }
    }
}
