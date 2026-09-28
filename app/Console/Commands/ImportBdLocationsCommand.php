<?php

namespace App\Console\Commands;

use App\Domain\Location\Actions\ImportBdLocations;
use Illuminate\Console\Command;

/**
 * `php artisan bd-locations:import` — see {@see ImportBdLocations} for what
 * each mode actually does.
 */
class ImportBdLocationsCommand extends Command
{
    protected $signature = 'bd-locations:import {--mode=import : validate|dry-run|import}';

    protected $description = 'Import the bundled Bangladesh location directory (Division/District/Upazila/Union)';

    public function handle(ImportBdLocations $importer): int
    {
        $mode = $this->option('mode');

        if (! in_array($mode, ['validate', 'dry-run', 'import'], true)) {
            $this->components->error("Unknown mode '{$mode}'. Use one of: validate, dry-run, import.");

            return self::FAILURE;
        }

        $report = $importer->handle($mode);

        if (! $report->isValid()) {
            $this->components->error("The bundled location data failed validation ({$mode}):");

            foreach ($report->problems as $problem) {
                $this->line("  - {$problem}");
            }

            return self::FAILURE;
        }

        if ($mode === 'validate') {
            $this->components->info('The bundled location data is valid — no orphans, duplicates or hierarchy mismatches.');

            return self::SUCCESS;
        }

        $this->components->info(($mode === 'dry-run' ? 'Dry run — nothing was written. ' : 'Import complete. ').sprintf(
            'divisions=%d districts=%d upazilas=%d unions=%d deactivated=%d',
            $report->counts['division'],
            $report->counts['district'],
            $report->counts['upazila'],
            $report->counts['union'],
            $report->counts['deactivated'],
        ));

        return self::SUCCESS;
    }
}
