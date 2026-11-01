<?php

namespace App\Console\Commands;

use App\Domain\Courier\Actions\SynchroniseSteadfastShipments;
use Illuminate\Console\Command;

/**
 * Asks Steadfast about one bounded batch of parcels that are still moving.
 *
 * What the scheduler runs, and what an operator runs by hand to catch up after
 * an outage. Prints counts only -- never a status payload, an address or a name.
 */
class SyncSteadfastShipments extends Command
{
    protected $signature = 'courier:steadfast-sync {--limit= : Parcels to check in this run (default: courier.steadfast.poll.batch)}';

    protected $description = 'Check Steadfast for the status of parcels that are still moving';

    public function handle(SynchroniseSteadfastShipments $synchronise): int
    {
        $limit = $this->option('limit');

        $summary = $synchronise->handle(is_numeric($limit) ? max(1, (int) $limit) : null);

        $this->info("Checked {$summary['checked']}, changed {$summary['changed']}, failed {$summary['failed']}.");

        return $summary['failed'] > 0 && $summary['checked'] === $summary['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
