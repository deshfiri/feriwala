<?php

namespace App\Console\Commands;

use App\Domain\Bank\Actions\ImportBdBanks;
use Illuminate\Console\Command;

/**
 * `php artisan bd-banks:import` — see {@see ImportBdBanks} for what each mode
 * actually does.
 */
class ImportBdBanksCommand extends Command
{
    protected $signature = 'bd-banks:import {--mode=import : validate|dry-run|import}';

    protected $description = 'Import the bundled Bangladesh bank and branch directory';

    public function handle(ImportBdBanks $importer): int
    {
        $mode = $this->option('mode');

        if (! in_array($mode, ['validate', 'dry-run', 'import'], true)) {
            $this->components->error("Unknown mode '{$mode}'. Use one of: validate, dry-run, import.");

            return self::FAILURE;
        }

        $report = $importer->handle($mode);

        if (! $report->isValid()) {
            $this->components->error("The bundled bank data failed validation ({$mode}):");

            foreach ($report->problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        if ($report->unmatchedDistricts !== []) {
            $this->components->warn('Unmatched districts (imported with no bd_locations link): '.implode(', ', $report->unmatchedDistricts));
        }

        if ($mode === 'validate') {
            $this->components->info('The bundled bank data is valid.');

            return self::SUCCESS;
        }

        $this->components->info(($mode === 'dry-run' ? 'Dry run — nothing was written. ' : 'Import complete. ').sprintf(
            'banks=%d branches=%d deactivated_banks=%d deactivated_branches=%d',
            $report->counts['banks'],
            $report->counts['branches'],
            $report->counts['deactivated_banks'],
            $report->counts['deactivated_branches'],
        ));

        return self::SUCCESS;
    }
}
