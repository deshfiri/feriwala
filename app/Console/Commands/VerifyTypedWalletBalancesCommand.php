<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Read-only deployment check that typed wallet balances changed no balance.
 *
 * Run `--snapshot=<file>` before migrating and `--against=<file>` after. The
 * snapshot holds, per account wallet and per Supplier wallet, the exact decimal
 * text Postgres stores (never a float) for every balance bucket, plus what each
 * ledger holds in total. The comparison fails — non-zero exit — if any of it
 * changed, if a wallet is missing, if a ledger gained or lost an entry, or if
 * any balance that existed before is anything other than withdrawable now.
 *
 * What the migration promises is exactly that: every balance that existed before
 * typed balances is withdrawable, and nothing in either ledger was touched. Both
 * halves are checked here, and the derived platform-credit figure is checked
 * against each wallet's own ledger entries.
 *
 * Nothing here writes to the database. A snapshot is production data: it is
 * refused inside the repository, `public/` or `storage/`, and written mode 0600.
 */
class VerifyTypedWalletBalancesCommand extends Command
{
    protected $signature = 'wallets:verify-typed-balances
        {--snapshot= : Write every wallet balance to this file (run before migrating)}
        {--against= : Compare the current database with a snapshot (run after migrating)}';

    protected $description = 'Read-only: prove typed wallet balances preserved every balance and left both ledgers untouched';

    /**
     * Per table: the columns that must not change, and the ledger it is explained by.
     *
     * @var array<string, array{columns: list<string>, ledger: string, key: string}>
     */
    private const WALLETS = [
        'wallets' => [
            'columns' => ['total', 'reserved', 'pending', 'hold', 'required_deposit', 'minimum_balance', 'cod_receivable'],
            'ledger' => 'ledger_entries',
            'key' => 'wallet_id',
        ],
        'supplier_wallets' => [
            'columns' => ['total', 'reserved', 'recovery'],
            'ledger' => 'supplier_ledger_entries',
            'key' => 'supplier_wallet_id',
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

        foreach ($data['tables'] as $table => $rows) {
            $this->line(sprintf('%s: %d wallets', $table, count($rows)));
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
            /** @var array{tables: array<string, array<int|string, array<string, string|null>>>} $before */
            $before = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Snapshot file is not valid JSON.');

            return self::FAILURE;
        }

        $now = $this->capture();

        /** @var array<string, list<string>> $problems */
        $problems = [];

        foreach (self::WALLETS as $table => $definition) {
            $alias = $table === 'wallets' ? 'wallet' : 'supplier wallet';

            foreach ($before['tables'][$table] ?? [] as $id => $row) {
                $label = "{$alias}#{$id}";

                if (! array_key_exists($id, $now[$table])) {
                    $problems['missing wallet'][] = $label;

                    continue;
                }

                foreach ([...$definition['columns'], 'ledger_entries', 'ledger_credit', 'ledger_debit'] as $column) {
                    if (($row[$column] ?? null) !== ($now[$table][$id][$column] ?? null)) {
                        $problems[in_array($column, $definition['columns'], true) ? 'balance changed' : 'ledger changed'][] = "{$label} {$column}";
                    }
                }

                // Every balance that existed before is withdrawable now: no
                // platform credit unless the ledger itself says there is some.
                $platform = (string) ($now[$table][$id]['platform_credit'] ?? '0.00');
                $derived = (string) ($now[$table][$id]['platform_credit_ledger'] ?? '0.00');

                // Postgres's own decimal text, so always numeric.
                assert(is_numeric($platform) && is_numeric($derived));

                if (bccomp($platform, $derived, 2) !== 0) {
                    $problems['platform credit disagrees with its ledger'][] = $label;
                }

                if (($row['ledger_entries'] ?? '0') === ($now[$table][$id]['ledger_entries'] ?? '0')
                    && bccomp($platform, '0', 2) !== 0) {
                    $problems['balance became platform credit'][] = $label;
                }

                // Every reservation and hold that existed before is withdrawable
                // money: none of it may have become platform credit.
                foreach (['reserved_platform_credit', 'hold_platform_credit'] as $claim) {
                    $claimed = (string) ($now[$table][$id][$claim] ?? '0.00');
                    assert(is_numeric($claimed));

                    if (bccomp($claimed, '0', 2) !== 0 && ($row['ledger_entries'] ?? '0') === ($now[$table][$id]['ledger_entries'] ?? '0')
                        && ! array_key_exists($claim, $row)) {
                        $problems['reservation became platform credit'][] = "{$label} {$claim}";
                    }
                }
            }
        }

        $rows = [];

        foreach (['missing wallet', 'balance changed', 'ledger changed', 'balance became platform credit', 'reservation became platform credit', 'platform credit disagrees with its ledger'] as $category) {
            $rows[] = [$category, count($problems[$category] ?? [])];
        }

        $this->table(['Typed-balance check', 'Unexpected'], $rows);

        foreach ($problems as $category => $labels) {
            $this->warn(strtoupper($category).': '.implode(', ', array_slice($labels, 0, self::LIST_LIMIT)).(count($labels) > self::LIST_LIMIT ? ' …' : ''));
        }

        $total = array_sum(array_map('count', $problems));

        $total === 0
            ? $this->info('Unexpected changes: 0. Every balance is identical, and every one is withdrawable.')
            : $this->error("Unexpected changes: {$total}. Do not continue the deployment.");

        return $total === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, array<int, array<string, string|null>>>
     */
    private function capture(): array
    {
        $state = [];

        foreach (self::WALLETS as $table => $definition) {
            $hasTypedColumn = $this->hasColumn($table, 'platform_credit');
            $ledgerHasType = $this->hasColumn($definition['ledger'], 'balance_type');

            $columns = array_map(fn (string $column) => "{$column}::text as {$column}", $definition['columns']);

            if ($hasTypedColumn) {
                $columns[] = 'platform_credit::text as platform_credit';
            }

            // Typed claims exist only once reservations were made after typed
            // balances: before that, every reservation and hold is withdrawable.
            $typedClaims = array_values(array_filter(
                ['reserved_platform_credit', 'hold_platform_credit'],
                fn (string $column) => $this->hasColumn($table, $column),
            ));

            foreach ($typedClaims as $column) {
                $columns[] = "{$column}::text as {$column}";
            }

            $ledger = DB::table($definition['ledger'])
                ->selectRaw($definition['key'].' as wallet_key, count(*) as entries, coalesce(sum(credit), 0)::text as credit, coalesce(sum(debit), 0)::text as debit')
                ->groupBy($definition['key'])
                ->get()
                ->keyBy('wallet_key');

            $platform = $ledgerHasType
                ? DB::table($definition['ledger'])
                    ->where('balance_type', 'platform_credit')
                    ->selectRaw($definition['key'].' as wallet_key, (coalesce(sum(credit), 0) - coalesce(sum(debit), 0))::text as net')
                    ->groupBy($definition['key'])
                    ->pluck('net', 'wallet_key')
                : collect();

            $rows = [];

            foreach (DB::table($table)->selectRaw('id, '.implode(', ', $columns))->orderBy('id')->get() as $wallet) {
                $row = [];

                foreach ($definition['columns'] as $column) {
                    $row[$column] = (string) $wallet->{$column};
                }

                $entries = $ledger->get($wallet->id);
                $row['ledger_entries'] = (string) ($entries->entries ?? 0);
                $row['ledger_credit'] = (string) ($entries->credit ?? '0');
                $row['ledger_debit'] = (string) ($entries->debit ?? '0');

                if ($hasTypedColumn) {
                    $row['platform_credit'] = (string) $wallet->platform_credit;
                    $row['platform_credit_ledger'] = (string) ($platform->get($wallet->id) ?? '0.00');
                }

                foreach ($typedClaims as $column) {
                    $row[$column] = (string) $wallet->{$column};
                }

                $rows[(int) $wallet->id] = $row;
            }

            $state[$table] = $rows;
        }

        return $state;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
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
