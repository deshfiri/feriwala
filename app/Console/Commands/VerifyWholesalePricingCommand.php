<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Read-only deployment check that protected prices survive a migration.
 *
 * Run `--snapshot=<file>` before migrating and `--against=<file>` after. The
 * snapshot holds, per row, the exact decimal text Postgres stores (never a
 * float) for every protected column, plus row, null and zero counts, the decimal
 * scales in use, and a checksum. The comparison fails — non-zero exit — if any
 * protected value is changed, nulled, zeroed, re-scaled, or if a row is missing
 * or duplicated.
 *
 * Nothing here writes to the database. A snapshot is production data: it is
 * refused inside the repository, `public/` or `storage/`, written mode 0600,
 * and the output names row ids and categories only unless `--show-values` is
 * passed. Nothing is written to the application log.
 */
class VerifyWholesalePricingCommand extends Command
{
    protected $signature = 'catalog:verify-wholesale-pricing
        {--snapshot= : Write the protected values to this file (run before migrating)}
        {--against= : Compare the current database with a snapshot (run after migrating)}
        {--allow-explicit-percentage : Do not treat a deliberate staff switch to Percentage as a changed price (development only)}
        {--show-values : Also print the before and after text of each affected value}';

    protected $description = 'Read-only: prove protected product and variation prices are unchanged by the pricing-method migration';

    /**
     * Protected columns per table, in checksum order. Identity columns first.
     *
     * @var array<string, array{identity: list<string>, money: list<string>}>
     */
    private const PROTECTED = [
        'products' => [
            'identity' => ['public_id', 'sku'],
            'money' => ['base_cost', 'wholesale_price', 'minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price'],
        ],
        'product_variants' => [
            'identity' => ['product_id', 'sku'],
            'money' => ['base_cost', 'wholesale_price'],
        ],
    ];

    private const LIST_LIMIT = 50;

    public function handle(): int
    {
        $snapshot = $this->option('snapshot');
        $against = $this->option('against');

        if (is_string($snapshot) && $snapshot !== '') {
            return $this->writeSnapshot($snapshot);
        }

        if (is_string($against) && $against !== '') {
            return $this->compare($against);
        }

        $this->error('Pass --snapshot=<file> before migrating, or --against=<file> after.');

        return self::INVALID;
    }

    private function writeSnapshot(string $path): int
    {
        if ($this->insideApplication($path)) {
            $this->error('Refusing to write a production snapshot inside the repository, public/ or storage/. Use a protected directory outside the release.');

            return self::FAILURE;
        }

        $data = ['version' => 1, 'taken_at' => now()->toIso8601String(), 'tables' => $this->capture()];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);

        foreach ($data['tables'] as $table => $state) {
            $this->line(sprintf('%s: %d rows, checksum %s', $table, $state['stats']['rows'], substr($state['stats']['checksum'], 0, 16)));
        }

        $this->info('Snapshot written (mode 0600).');

        return self::SUCCESS;
    }

    private function compare(string $path): int
    {
        if (! is_file($path)) {
            $this->error('Snapshot file not found.');

            return self::FAILURE;
        }

        try {
            /** @var array{tables: array<string, array{rows: array<int|string, array<string, string|null>>, stats: array<string, mixed>}>} $before */
            $before = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Snapshot file is not valid JSON.');

            return self::FAILURE;
        }

        $now = $this->capture();
        $allowPercentage = (bool) $this->option('allow-explicit-percentage');
        $percentageProducts = $allowPercentage
            ? DB::table('products')->where('wholesale_pricing_method', 'percentage')->pluck('id')->flip()->all()
            : [];

        /** @var array<string, list<string>> $problems category => affected row labels */
        $problems = [];
        /** @var array<string, string> $details */
        $details = [];
        $report = static function (string $category, string $label, ?string $was = null, ?string $is = null) use (&$problems, &$details): void {
            $problems[$category][] = $label;

            if ($was !== null || $is !== null) {
                $details["{$category}|{$label}"] = sprintf('%s -> %s', $was ?? 'NULL', $is ?? 'NULL');
            }
        };

        foreach (self::PROTECTED as $table => $columns) {
            $old = $before['tables'][$table]['rows'] ?? [];
            $new = $now[$table]['rows'];
            $alias = $table === 'products' ? 'product' : 'variant';

            foreach ($old as $id => $row) {
                $label = "{$alias}#{$id}";

                if (! array_key_exists($id, $new)) {
                    $report('missing row', $label);

                    continue;
                }

                foreach ([...$columns['identity'], ...$columns['money']] as $column) {
                    $was = $row[$column] ?? null;
                    $is = $new[$id][$column] ?? null;

                    if ($was === $is) {
                        continue;
                    }

                    if ($allowPercentage && $table === 'products' && $column === 'wholesale_price' && isset($percentageProducts[$id])) {
                        continue;
                    }

                    $report($this->classify($table, $column, $was, $is), $label.' '.$column, $was, $is);
                }
            }

            foreach ($now[$table]['duplicates'] as $duplicate) {
                $report('duplicate records', "{$alias} {$duplicate}");
            }

            // Whole-table cross-check over exactly the rows the snapshot knew
            // about, so a change the per-column logic did not anticipate still
            // cannot pass: counts, nulls, zeros, scales and checksum must agree
            // unless something above already explained a difference.
            // (Rows a developer explicitly allowed to differ are left out of both sides.)
            $comparable = $table === 'products' ? array_diff_key($old, $percentageProducts) : $old;

            if ($problems === [] && $this->stats($comparable) !== $this->stats(array_intersect_key($new, $comparable))) {
                $report('aggregate mismatch', $table);
            }
        }

        foreach ($now as $table => $state) {
            $added = count($state['rows']) - count(array_intersect_key($state['rows'], $before['tables'][$table]['rows'] ?? []));
            $this->line(sprintf('%s: %d rows then, %d now (%d added since, not protected)', $table, count($before['tables'][$table]['rows'] ?? []), count($state['rows']), $added));
        }

        $rows = [];

        foreach (['missing row', 'price changed', 'bound changed', 'new null', 'non-zero became zero', 'decimal scale changed', 'identity changed', 'duplicate records', 'aggregate mismatch'] as $category) {
            $rows[] = [$category, count($problems[$category] ?? [])];
        }

        $this->table(['Protected-value check', 'Unexpected'], $rows);

        foreach ($problems as $category => $labels) {
            $this->warn(strtoupper($category).': '.implode(', ', array_slice($labels, 0, self::LIST_LIMIT)).(count($labels) > self::LIST_LIMIT ? ' …' : ''));

            if ($this->option('show-values')) {
                foreach (array_slice($labels, 0, self::LIST_LIMIT) as $label) {
                    if (isset($details["{$category}|{$label}"])) {
                        $this->line("  {$label}: ".$details["{$category}|{$label}"]);
                    }
                }
            }
        }

        $total = array_sum(array_map('count', $problems));

        $total === 0
            ? $this->info('Unexpected changes: 0. Every protected value is identical.')
            : $this->error("Unexpected changes: {$total}. Do not continue the deployment.");

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function classify(string $table, string $column, ?string $was, ?string $is): string
    {
        if (in_array($column, self::PROTECTED[$table]['identity'], true)) {
            return 'identity changed';
        }

        if (in_array($column, ['minimum_selling_price', 'maximum_selling_price', 'suggested_selling_price'], true)) {
            return 'bound changed';
        }

        if ($was !== null && $is === null) {
            return 'new null';
        }

        if ($was !== null && $is !== null && bccomp($was, '0', 2) !== 0 && bccomp($is, '0', 2) === 0) {
            return 'non-zero became zero';
        }

        if ($was !== null && $is !== null && bccomp($was, $is, 12) === 0) {
            return 'decimal scale changed';
        }

        return 'price changed';
    }

    /**
     * @return array<string, array{rows: array<int|string, array<string, string|null>>, stats: array<string, mixed>, duplicates: list<string>}>
     */
    private function capture(): array
    {
        $state = [];

        foreach (self::PROTECTED as $table => $columns) {
            // Money is read as Postgres's own decimal text, never a float.
            $expressions = [
                'id',
                ...$columns['identity'],
                ...array_map(fn (string $column) => "{$column}::text as {$column}", $columns['money']),
            ];

            $rows = [];

            // Trashed and archived rows included: this is the raw table.
            foreach (DB::table($table)->selectRaw(implode(', ', $expressions))->orderBy('id')->get() as $row) {
                $values = [];

                foreach ([...$columns['identity'], ...$columns['money']] as $column) {
                    $values[$column] = $row->{$column} === null ? null : (string) $row->{$column};
                }

                $rows[(int) $row->id] = $values;
            }

            $state[$table] = [
                'rows' => $rows,
                'stats' => $this->stats($rows),
                'duplicates' => $this->duplicates($table),
            ];
        }

        return $state;
    }

    /**
     * Counts, nulls, zeros, decimal scales and a deterministic checksum of
     * every protected value, in id order.
     *
     * @param  array<int|string, array<string, string|null>>  $rows
     * @return array<string, mixed>
     */
    private function stats(array $rows): array
    {
        ksort($rows);

        $nulls = [];
        $zeros = [];
        $scales = [];
        $lines = [];

        foreach ($rows as $id => $row) {
            $lines[] = $id.'|'.implode('|', array_map(fn (?string $value) => $value ?? '\N', $row));

            foreach ($row as $column => $value) {
                if ($value === null) {
                    $nulls[$column] = ($nulls[$column] ?? 0) + 1;

                    continue;
                }

                if (in_array($column, ['public_id', 'sku', 'product_id'], true) || ! is_numeric($value)) {
                    continue;
                }

                $scale = (string) (str_contains($value, '.') ? strlen(explode('.', $value, 2)[1]) : 0);
                $scales[$column][$scale] = ($scales[$column][$scale] ?? 0) + 1;

                if (bccomp($value, '0', 12) === 0) {
                    $zeros[$column] = ($zeros[$column] ?? 0) + 1;
                }
            }
        }

        ksort($nulls);
        ksort($zeros);
        ksort($scales);

        return [
            'rows' => count($rows),
            'nulls' => $nulls,
            'zeros' => $zeros,
            'scales' => $scales,
            'checksum' => hash('sha256', implode("\n", $lines)),
        ];
    }

    /**
     * @return list<string>
     */
    private function duplicates(string $table): array
    {
        $found = [];

        foreach (['sku', ...($table === 'products' ? ['public_id'] : [])] as $column) {
            foreach (DB::table($table)->select($column)->groupBy($column)->havingRaw('count(*) > 1')->pluck($column) as $value) {
                $found[] = "{$column} '{$value}'";
            }
        }

        return $found;
    }

    private function insideApplication(string $path): bool
    {
        $directory = realpath(dirname($path));

        if ($directory === false) {
            return true;
        }

        foreach ([base_path(), public_path(), storage_path()] as $protected) {
            $root = realpath($protected);

            if ($root !== false && ($directory === $root || str_starts_with($directory.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }
}
