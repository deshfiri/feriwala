<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\StockReservations;
use App\Support\Concurrency\Exceptions\LockTimeout;

/**
 * Give back the stock held by reservations whose window has run out (§19.1,
 * contract §6.1.2, P3-26).
 *
 * The contract is explicit that expired reservations are released **by the
 * scheduler, not lazily at read time**, so stock frees up predictably rather
 * than only when somebody happens to look. This is that pass.
 *
 * Each reservation is expired through the reservation service, under its own
 * lock and in its own transaction, so one that was committed, released or
 * extended between this query and its turn is left exactly as it is — the
 * service re-reads it under the lock and refuses — and a reservation locked by
 * somebody else is simply left for the next run.
 */
class ReleaseExpiredReservations
{
    /** How many reservations one pass reads at a time. */
    public const CHUNK = 200;

    public function __construct(
        protected StockReservations $reservations,
    ) {}

    /**
     * @return int how many reservations this pass expired
     */
    public function handle(): int
    {
        $expired = 0;

        StockReservation::query()
            ->active()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->lazyById(self::CHUNK)
            ->each(function (StockReservation $reservation) use (&$expired) {
                try {
                    $this->reservations->expire($reservation);
                    $expired++;
                } catch (InventoryRefused|LockTimeout) {
                    // Ended, extended or busy since it was read. Nothing to do
                    // now; a reservation still due is picked up next run.
                }
            });

        return $expired;
    }
}
