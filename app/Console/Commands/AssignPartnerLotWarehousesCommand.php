<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actions\AssignPartnerLotWarehouses;
use Illuminate\Console\Command;

/**
 * Reports which Banij warehouse holds each Partner cost lot, and -- only with
 * --apply -- records it for the lots where the records prove exactly one.
 *
 * Dry run by default. Ambiguous and missing lots are listed for staff review
 * and never assigned. See {@see AssignPartnerLotWarehouses}.
 */
class AssignPartnerLotWarehousesCommand extends Command
{
    protected $signature = 'inventory:partner-lot-warehouses
        {--apply : Assign the warehouse to lots where exactly one is proven (ambiguous and missing lots are never touched)}';

    protected $description = 'Audit which Banij warehouse holds each Partner cost lot; dry run unless --apply';

    public function handle(AssignPartnerLotWarehouses $assign): int
    {
        $apply = (bool) $this->option('apply');
        $result = $assign->handle($apply);

        $this->table(
            ['Already assigned', 'Safe', 'Ambiguous', 'Missing'],
            [[$result['counts']['assigned'], $result['counts']['safe'], $result['counts']['ambiguous'], $result['counts']['missing']]],
        );

        foreach ($result['review'] as $row) {
            $this->line(sprintf(
                '  review  lot %s  stock row %d  %s  warehouses [%s]',
                $row['lot'],
                $row['stock_item'],
                $row['classification'],
                implode(', ', $row['warehouses']),
            ));
        }

        if ($apply) {
            $this->components->info("Assigned a warehouse to {$result['assigned_now']} safe lot(s). Ambiguous and missing lots were left for review.");
        } else {
            $this->components->info('Dry run: nothing was changed. Run with --apply to assign the safe lots only.');
        }

        return self::SUCCESS;
    }
}
