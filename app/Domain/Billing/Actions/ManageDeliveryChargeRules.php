<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Adding and closing delivery-charge rules (beta-critical batch, Commit 2).
 *
 * The same discipline {@see ManageFeeRules}
 * already holds `fee_rules` to: a rule is never edited into a new price,
 * only closed and replaced, so a shipment's own frozen {@see
 * \App\Domain\Courier\Models\Shipment::$delivery_charge_rule_snapshot} can
 * always be explained by the rule that was actually in force when it priced
 * it. Overlap is refused rather than resolved -- two active rules covering
 * the same weight band and the same area/courier scope at once would make
 * the charge depend on which row the resolver happened to read first.
 */
class ManageDeliveryChargeRules
{
    public function __construct(
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    public function create(
        User $actor,
        int $weightFromGrams,
        ?int $weightToGrams,
        Money $baseCharge,
        ?Money $perKgCharge,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil = null,
        ?string $area = null,
        ?int $courierProviderId = null,
        int $priority = 0,
        ?string $note = null,
    ): DeliveryChargeRule {
        if ($baseCharge->isNegative() || ($perKgCharge !== null && $perKgCharge->isNegative())) {
            throw new InvalidArgumentException('A delivery charge cannot be negative.');
        }

        if ($weightToGrams !== null && $weightToGrams <= $weightFromGrams) {
            throw new InvalidArgumentException('The upper weight bound must be greater than the lower one.');
        }

        if ($effectiveUntil !== null && ! $effectiveUntil->isAfter($effectiveFrom)) {
            throw new InvalidArgumentException('A delivery charge rule must end after it begins.');
        }

        return $this->database->transaction(function () use (
            $actor, $weightFromGrams, $weightToGrams, $baseCharge, $perKgCharge,
            $effectiveFrom, $effectiveUntil, $area, $courierProviderId, $priority, $note,
        ) {
            $this->refuseOverlap($weightFromGrams, $weightToGrams, $area, $courierProviderId, $effectiveFrom, $effectiveUntil);

            $rule = DeliveryChargeRule::create([
                'weight_from_grams' => $weightFromGrams,
                'weight_to_grams' => $weightToGrams,
                'currency_code' => $baseCharge->currency->value,
                'base_charge' => $baseCharge,
                'per_kg_charge' => $perKgCharge,
                'area' => $area,
                'courier_provider_id' => $courierProviderId,
                'priority' => $priority,
                'effective_from' => $effectiveFrom,
                'effective_until' => $effectiveUntil,
                'is_active' => true,
                'note' => $note,
                'created_by' => $actor->id,
            ]);

            $this->audit->handle(new AuditEntry(
                action: 'billing.delivery_charge_rule_created',
                actorId: $actor->id,
                auditableType: DeliveryChargeRule::class,
                auditableId: $rule->id,
                after: [
                    'weight_from_grams' => $weightFromGrams,
                    'weight_to_grams' => $weightToGrams,
                    'base_charge' => $baseCharge->jsonSerialize(),
                    'per_kg_charge' => $perKgCharge?->jsonSerialize(),
                    'area' => $area,
                    'courier_provider_id' => $courierProviderId,
                    'effective_from' => $effectiveFrom->toIso8601String(),
                    'effective_until' => $effectiveUntil?->toIso8601String(),
                ],
                reason: $note,
                module: 'delivery_settings',
            ));

            return $rule;
        });
    }

    /**
     * Close a rule from now, leaving what it already priced intact.
     */
    public function close(User $actor, DeliveryChargeRule $rule): DeliveryChargeRule
    {
        return $this->database->transaction(function () use ($actor, $rule) {
            /** @var DeliveryChargeRule $locked */
            $locked = DeliveryChargeRule::query()->lockForUpdate()->findOrFail($rule->id);

            if (! $locked->is_active) {
                return $locked;
            }

            $locked->forceFill([
                'is_active' => false,
                'effective_until' => $locked->effective_until ?? now(),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'billing.delivery_charge_rule_closed',
                actorId: $actor->id,
                auditableType: DeliveryChargeRule::class,
                auditableId: $locked->id,
                before: ['is_active' => true],
                after: ['is_active' => false, 'effective_until' => $locked->effective_until?->toIso8601String()],
                module: 'delivery_settings',
            ));

            $rule->setRawAttributes($locked->getAttributes(), sync: true);

            return $locked;
        });
    }

    /**
     * Refuse a new rule whose weight band and area/courier scope overlap one
     * already active for an overlapping window.
     */
    protected function refuseOverlap(
        int $weightFromGrams,
        ?int $weightToGrams,
        ?string $area,
        ?int $courierProviderId,
        CarbonImmutable $from,
        ?CarbonImmutable $until,
    ): void {
        $query = DeliveryChargeRule::query()
            ->where('is_active', true)
            ->lockForUpdate();

        $area === null ? $query->whereNull('area') : $query->where('area', $area);
        $courierProviderId === null
            ? $query->whereNull('courier_provider_id')
            : $query->where('courier_provider_id', $courierProviderId);

        // Two weight bands overlap unless one ends at or before the other
        // begins. An open upper bound is "unbounded", the same as an open
        // effective_until means "for ever".
        $query->where(fn (Builder $inner) => $inner
            ->whereNull('weight_to_grams')
            ->orWhere('weight_to_grams', '>', $weightFromGrams));

        if ($weightToGrams !== null) {
            $query->where('weight_from_grams', '<', $weightToGrams);
        }

        $query->where(fn (Builder $inner) => $inner
            ->whereNull('effective_until')
            ->orWhere('effective_until', '>', $from));

        if ($until !== null) {
            $query->where('effective_from', '<', $until);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                'A delivery charge rule already covers that weight band and scope for that period. Close it first.'
            );
        }
    }
}
