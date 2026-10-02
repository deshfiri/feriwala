<?php

namespace App\Domain\Storage\Actions;

use App\Domain\Storage\Data\MigrationOutcome;
use App\Domain\Storage\Data\MigrationSourceDefinition;
use App\Domain\Storage\Data\StorageMigrationReport;
use App\Domain\Storage\MigrationSources;
use App\Domain\Storage\R2StorageSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Orchestrates one run of `storage:migrate-to-r2` across every known
 * source (beta-critical batch, Commit 5) -- the Artisan command itself
 * only prints what this returns.
 *
 * Four modes, each read-only except `migrate`:
 *
 *   - `inventory` -- counts only: how many rows exist, how many are
 *     already on R2, how many are still eligible. No disk access at all.
 *   - `dry-run` -- the same counts, plus how many eligible rows are
 *     missing their local file. Still no network access and no writes;
 *     safe to run at any time, including before R2 is configured.
 *   - `migrate` -- see {@see MigrateManagedFileToR2::migrateRow()}.
 *     Resumable: a row already on `r2` is excluded from the next run's
 *     query, so re-running after a partial failure picks up exactly
 *     where it left off.
 *   - `verify` -- re-checks every row already on `r2` against its own
 *     recorded size and checksum. Never writes.
 */
class RunStorageMigration
{
    /** @var list<string> */
    public const MODES = ['inventory', 'dry-run', 'migrate', 'verify'];

    public function __construct(
        protected MigrateManagedFileToR2 $migrator,
        protected R2StorageSettings $r2Settings,
    ) {}

    /**
     * @throws RuntimeException when the mode is not one of {@see self::MODES}, or `migrate`/`verify` is asked for before R2 is configured and switched on
     */
    public function handle(string $mode, int $chunk = 200): StorageMigrationReport
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new RuntimeException("Unknown mode '{$mode}'. Use one of: ".implode(', ', self::MODES).'.');
        }

        if (in_array($mode, ['migrate', 'verify'], true) && ! $this->r2Settings->isAvailable()) {
            throw new RuntimeException('Cloudflare R2 is not configured and switched on -- there is nothing to migrate to yet.');
        }

        $sources = [];

        foreach (MigrationSources::all() as $source) {
            $sources[$source->key] = match ($mode) {
                'inventory' => $this->inventory($source),
                'dry-run' => $this->dryRun($source),
                'migrate' => $this->runOutcomes($source, fn (Model $row) => $this->migrator->migrateRow($row, $source), onlyNotOnR2: true, chunk: $chunk),
                'verify' => $this->runOutcomes($source, fn (Model $row) => $this->migrator->verifyRow($row, $source), onlyNotOnR2: false, chunk: $chunk),
            };
        }

        return new StorageMigrationReport($mode, $sources);
    }

    /**
     * @return array<string, int>
     */
    protected function inventory(MigrationSourceDefinition $source): array
    {
        $model = $source->modelClass;
        $total = $model::query()->count();
        $onR2 = $model::query()->where($source->diskColumn, 'r2')->count();

        return [
            'total' => $total,
            'already_on_r2' => $onR2,
            'eligible' => $total - $onR2,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function dryRun(MigrationSourceDefinition $source): array
    {
        $counts = $this->inventory($source);
        $missing = 0;

        $model = $source->modelClass;
        $model::query()
            ->where($source->diskColumn, '!=', 'r2')
            ->chunkById(200, function ($rows) use ($source, &$missing) {
                foreach ($rows as $row) {
                    $disk = (string) $row->getAttribute($source->diskColumn);
                    $path = (string) $row->getAttribute($source->pathColumn);

                    if (! Storage::disk($disk)->exists($path)) {
                        $missing++;
                    }
                }
            });

        $counts['missing_local_file'] = $missing;

        return $counts;
    }

    /**
     * @param  callable(Model): MigrationOutcome  $callback
     * @return array<string, int>
     */
    protected function runOutcomes(MigrationSourceDefinition $source, callable $callback, bool $onlyNotOnR2, int $chunk): array
    {
        $model = $source->modelClass;
        $query = $onlyNotOnR2
            ? $model::query()->where($source->diskColumn, '!=', 'r2')
            : $model::query()->where($source->diskColumn, 'r2');

        $tally = [];

        $query->chunkById($chunk, function ($rows) use ($callback, &$tally) {
            foreach ($rows as $row) {
                $status = $callback($row)->status;
                $tally[$status] = ($tally[$status] ?? 0) + 1;
            }
        });

        return $tally;
    }
}
