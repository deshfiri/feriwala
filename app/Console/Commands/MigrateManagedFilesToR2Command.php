<?php

namespace App\Console\Commands;

use App\Domain\Storage\Actions\RunStorageMigration;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `php artisan storage:migrate-to-r2` -- see {@see RunStorageMigration} for
 * what each mode actually does (beta-critical batch, Commit 5).
 */
class MigrateManagedFilesToR2Command extends Command
{
    protected $signature = 'storage:migrate-to-r2
        {--mode=inventory : inventory|dry-run|migrate|verify}
        {--chunk=200 : rows read per chunk}
        {--report= : path to write the JSON report to; defaults to stdout}';

    protected $description = 'Move already-stored local files onto Cloudflare R2 once it is configured -- inventory, dry-run, migrate or verify';

    public function handle(RunStorageMigration $migration): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, RunStorageMigration::MODES, true)) {
            $this->components->error(sprintf(
                "Unknown mode '%s'. Use one of: %s.",
                $mode,
                implode(', ', RunStorageMigration::MODES),
            ));

            return self::FAILURE;
        }

        try {
            $report = $migration->handle($mode, (int) $this->option('chunk'));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $json = (string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $path = $this->option('report');

        if (is_string($path) && $path !== '') {
            file_put_contents($path, $json);
            $this->components->info("Report written to {$path}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
