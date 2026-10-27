<?php

namespace App\Domain\Damage\Actions;

use App\Domain\Access\Enums\PermissionAction;
use App\Domain\Access\Enums\PermissionModule;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Damage\Actions\Concerns\GuardsDamageActions;
use App\Domain\Damage\Data\DamageInspection;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Models\User;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;

/**
 * Record what inspection found, and put every affected unit where it belongs
 * (D35).
 *
 * **One disposition for every unit.** The dispositions given must add up to the
 * case's quantity exactly: none unaccounted for, none counted twice. A unit that
 * is fine goes back on sale; a damaged one is held in the damaged bucket and is
 * never sellable; one nobody can judge yet is held apart. Where the goods are in
 * Banij's hands that is a stock movement through the stock ledgers, once per
 * disposition under a key of its own, from the bucket the units are held in — so a
 * repeat restocks nothing twice, and nothing is ever written onto a stock figure.
 * Where they are not (the item stayed where it was), there is no stock to move and
 * the only honest finding is that it is damaged.
 *
 * An inspection that finds **nothing damaged** has no loss to assign: its units
 * are restocked and the case is closed as rejected. Otherwise the case goes to
 * liability review. Nothing financial happens here either way.
 */
class RecordDamageInspection
{
    use GuardsDamageActions;

    public function __construct(
        protected MoveDamageStock $stock,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws DamageRefused
     * @throws AuthorizationException
     */
    public function handle(User $actor, OrderDamage $damage, DamageInspection $inspection): OrderDamage
    {
        $this->authorizeStaff($actor, PermissionAction::Inspect, 'inspect a damaged item');

        $inspected = $this->database->transaction(function () use ($actor, $damage, $inspection) {
            $locked = $this->locked($damage);

            // Already inspected by the request that got here first.
            if ($locked->inspected_at !== null) {
                return $locked;
            }

            if ($locked->status === DamageStatus::AwaitingReceipt) {
                throw DamageRefused::parcelNotReceived();
            }

            if ($locked->status !== DamageStatus::UnderInspection) {
                throw DamageRefused::notInThatState();
            }

            $units = $this->validated($locked, $inspection);

            if ($inspection->warehouse !== null && $locked->warehouse_id === null) {
                $locked->forceFill(['warehouse_id' => $inspection->warehouse->id]);
            }

            foreach ($units as $unit) {
                $this->place($locked, $unit['disposition'], $unit['quantity'], $actor);
            }

            $damagedUnits = collect($units)
                ->filter(fn (array $unit) => $unit['disposition'] === ReturnDisposition::Damaged)
                ->sum('quantity');

            $locked->forceFill([
                'cause' => $inspection->cause,
                'damage_condition' => $inspection->condition ?? $locked->damage_condition,
                'inspection_note' => $inspection->note === null ? null : trim($inspection->note),
                'inspected_by' => $actor->id,
                'inspected_at' => CarbonImmutable::now(),
            ]);

            // Nothing damaged: nothing to assign, nothing to cost.
            if ($damagedUnits === 0) {
                $locked->moveTo(
                    DamageStatus::Rejected,
                    new StatusChange(
                        actorId: $actor->id,
                        reason: 'Inspection found no damage; the units were not damaged.',
                        internalNote: $inspection->note,
                        publicNote: 'damages.notes.no_damage_found',
                    ),
                    OrderStatusChangeSource::Staff,
                );

                return $locked;
            }

            $locked->moveTo(
                DamageStatus::LiabilityReview,
                new StatusChange(
                    actorId: $actor->id,
                    reason: 'Inspection recorded; waiting for the decision on who bears the loss.',
                    internalNote: $inspection->note,
                    publicNote: 'damages.notes.inspection_recorded',
                ),
                OrderStatusChangeSource::Staff,
            );

            return $locked;
        });

        $this->audit->handle(new AuditEntry(
            action: 'damage.inspected',
            actorId: $actor->id,
            auditableType: OrderDamage::class,
            auditableId: $inspected->id,
            after: [
                'reference' => $inspected->reference,
                'status' => $inspected->status->value,
                'cause' => $inspected->cause?->value,
                'dispositions' => $inspected->dispositions->map(fn ($row) => [
                    'disposition' => $row->disposition->value,
                    'quantity' => $row->quantity,
                ])->all(),
            ],
            note: $inspection->note,
            accountId: $inspected->business_account_id,
            module: PermissionModule::Damage->value,
        ));

        $damage->setRawAttributes($inspected->getAttributes(), sync: true);

        return $inspected;
    }

    /**
     * Every unit accounted for, once, and nothing impossible.
     *
     * @return list<array{disposition: ReturnDisposition, quantity: int}>
     *
     * @throws DamageRefused
     */
    protected function validated(OrderDamage $damage, DamageInspection $inspection): array
    {
        $units = [];
        $seen = [];
        $total = 0;

        foreach ($inspection->dispositions as $unit) {
            if ($unit['quantity'] < 1 || isset($seen[$unit['disposition']->value])) {
                throw DamageRefused::inspectionMustAccountForEveryUnit($damage->quantity, $total);
            }

            // An item still where it was is only ever damaged: there is no stock to
            // restock or quarantine.
            if (! $damage->in_custody && $unit['disposition'] !== ReturnDisposition::Damaged) {
                throw DamageRefused::dispositionNeedsCustody();
            }

            $seen[$unit['disposition']->value] = true;
            $total += $unit['quantity'];
            $units[] = $unit;
        }

        if ($total !== $damage->quantity) {
            throw DamageRefused::inspectionMustAccountForEveryUnit($damage->quantity, $total);
        }

        return $units;
    }

    /**
     * Write one disposition, with the movement that put the units there.
     *
     * @throws DamageRefused
     */
    protected function place(OrderDamage $damage, ReturnDisposition $disposition, int $quantity, User $actor): void
    {
        $from = $damage->held_bucket;
        $to = $disposition->bucket();
        $movement = ['stock_movement_id' => null, 'partner_stock_movement_id' => null];

        // Units already in the bucket they belong in need no movement; the
        // decision is still recorded.
        if ($damage->in_custody && $from !== null && $from !== $to) {
            $movement = $this->stock->handle(
                $damage,
                $from,
                $to,
                $quantity,
                'disposition:'.$disposition->value,
                $disposition->label().' after inspection of '.$damage->reference.'.',
                $actor,
            );
        }

        $damage->dispositions()->create([
            'disposition' => $disposition,
            'quantity' => $quantity,
            'from_bucket' => $damage->in_custody ? $from : null,
            'to_bucket' => $damage->in_custody ? $to : null,
            'stock_movement_id' => $movement['stock_movement_id'],
            'partner_stock_movement_id' => $movement['partner_stock_movement_id'],
            'moved_at' => $movement['stock_movement_id'] !== null || $movement['partner_stock_movement_id'] !== null
                ? CarbonImmutable::now()
                : null,
        ]);
    }
}
