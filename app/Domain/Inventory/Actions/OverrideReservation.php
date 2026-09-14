<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Domain\Inventory\StockReservations;
use App\Models\User;
use App\Support\Concurrency\DistributedLock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A person ending or extending a reservation by hand (contract §6.1.2, P3-26).
 *
 * "An authorized user may override a reservation manually; the override is
 * written to the audit log." `inventory.approve`, asked here as well as at the
 * controller, and a reason every time — somebody freeing stock an order was
 * waiting on, or holding it past its window, is the decision people later ask
 * about.
 *
 * Neither override can oversell: releasing goes through the reservation service
 * and the stock ledger like any other release, and extending moves only a date —
 * the units were already set aside.
 */
class OverrideReservation
{
    public const MINIMUM_REASON = 10;

    /** An override holds stock a week at most; beyond that it is not a reservation. */
    public const MAXIMUM_EXTENSION_HOURS = 168;

    public function __construct(
        protected StockReservations $reservations,
        protected DistributedLock $locks,
        protected RecordAuditLog $audit,
    ) {}

    /**
     * Free the reserved units now.
     *
     * @throws AuthorizationException
     * @throws InventoryRefused when the reservation has already ended another way
     */
    public function release(User $actor, StockReservation $reservation, string $reason): StockReservation
    {
        InventoryPolicy::authorize(InventoryPolicy::canApprove($actor));

        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $reservation, $reason) {
            $before = $reservation->status->value;

            $released = $this->reservations->release($reservation, $reason, $actor->id);

            $this->record($actor, 'inventory.reservation_released_by_hand', $released, ['status' => $before], ['status' => $released->status->value], $reason);

            return $released;
        });
    }

    /**
     * Move an active reservation's expiry.
     *
     * @throws AuthorizationException
     * @throws InventoryRefused when the reservation has already ended
     * @throws InvalidArgumentException when the new expiry is past or too far away
     */
    public function extend(User $actor, StockReservation $reservation, CarbonImmutable $until, string $reason): StockReservation
    {
        InventoryPolicy::authorize(InventoryPolicy::canApprove($actor));

        $reason = $this->reason($reason);

        if (! $until->isFuture() || $until->greaterThan(CarbonImmutable::now()->addHours(self::MAXIMUM_EXTENSION_HOURS))) {
            throw new InvalidArgumentException('A reservation can be held until a moment within the next '.self::MAXIMUM_EXTENSION_HOURS.' hours.');
        }

        return $this->locks->run('inventory:reservation:'.$reservation->id, fn () => DB::transaction(
            function () use ($actor, $reservation, $until, $reason) {
                /** @var StockReservation $locked */
                $locked = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);

                if ($locked->status !== StockReservationStatus::Active) {
                    throw InventoryRefused::reservationEnded($locked->status);
                }

                $before = $locked->expires_at->toIso8601String();

                $locked->forceFill(['expires_at' => $until, 'overridden_by' => $actor->id])->save();

                $this->record($actor, 'inventory.reservation_extended', $locked, ['expires_at' => $before], ['expires_at' => $until->toIso8601String()], $reason);

                $reservation->setRawAttributes($locked->getAttributes(), sync: true);

                return $locked;
            },
        ));
    }

    protected function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < self::MINIMUM_REASON) {
            throw new InvalidArgumentException('An override needs a reason of at least '.self::MINIMUM_REASON.' characters.');
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function record(User $actor, string $action, StockReservation $reservation, array $before, array $after, string $reason): void
    {
        $this->audit->handle(new AuditEntry(
            action: $action,
            actorId: $actor->id,
            auditableType: StockReservation::class,
            auditableId: $reservation->id,
            before: ['reference' => $reservation->reference, ...$before],
            after: ['reference' => $reservation->reference, ...$after],
            reason: $reason,
            module: 'inventory',
            isSensitive: true,
        ));
    }
}
