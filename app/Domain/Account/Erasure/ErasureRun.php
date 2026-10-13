<?php

namespace App\Domain\Account\Erasure;

use App\Domain\Account\Data\ErasureResult;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One erasure's bookkeeping: delete what is safe to delete, anonymize what must
 * stay, and say what could not be changed.
 *
 * It never switches a database trigger off, never deletes an audit row, and never
 * forces a delete past a foreign key. Each change is its own savepoint: where the
 * database refuses one — a row a retained record still points to, an append-only
 * table that forbids an update — that table is reported as retained with the
 * reason, and everything else carries on. So the erasure removes as much as the
 * database's own rules allow and states honestly what it left.
 *
 * With `$preview` set nothing is changed; each step only counts the rows it
 * would touch.
 */
class ErasureRun
{
    /** @var array<string, int> */
    protected array $deleted = [];

    /** @var array<string, int> */
    protected array $anonymized = [];

    /** @var array<string, string> */
    protected array $retained = [];

    /** @var array<int, array{disk: string, path: string}> */
    protected array $files = [];

    /** @var array<int, string> */
    protected array $notes = [];

    /** @var array<string, array<string, object|null>> */
    protected array $columnCache = [];

    public function __construct(
        protected readonly string $subjectType,
        protected readonly string $tombstone,
        protected readonly bool $preview,
    ) {}

    public function isPreview(): bool
    {
        return $this->preview;
    }

    /**
     * Delete rows. Where a retained record still points at them, replace the
     * listed columns instead so the personal details are gone either way.
     *
     * @param  Closure(Builder): Builder  $where
     * @param  array<int, string>  $otherwiseBlank
     */
    public function purge(string $table, Closure $where, array $otherwiseBlank = []): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $count = (int) $where(DB::table($table))->count();

        if ($count === 0) {
            return;
        }

        if ($this->preview) {
            $this->deleted[$table] = ($this->deleted[$table] ?? 0) + $count;

            return;
        }

        try {
            DB::transaction(fn () => $where(DB::table($table))->delete());
            $this->deleted[$table] = ($this->deleted[$table] ?? 0) + $count;
        } catch (QueryException $exception) {
            $this->refusal($exception);

            if ($otherwiseBlank === []) {
                $this->retained[$table] = 'still referenced by records that must be kept';

                return;
            }

            $this->blank($table, $where, $otherwiseBlank);
        }
    }

    /**
     * Replace personal columns with a neutral placeholder, on rows that stay.
     *
     * @param  Closure(Builder): Builder  $where
     * @param  array<int, string>  $columns
     */
    public function blank(string $table, Closure $where, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        // One column at a time: a table may protect some columns and not others
        // (an order's customer snapshot, say, but not its note), and one refused
        // column must not stop the rest being erased.
        foreach ($columns as $column) {
            $placeholder = $this->placeholder($table, $column);

            if ($placeholder !== false) {
                $this->update($table, $where, [$column => $placeholder], label: $table.'.'.$column);
            }
        }
    }

    /**
     * Set explicit values on rows that stay — the tombstone itself.
     *
     * @param  Closure(Builder): Builder  $where
     * @param  array<string, mixed>  $values
     */
    public function update(string $table, Closure $where, array $values, bool $essential = false, ?string $label = null): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $values = array_filter($values, fn ($_, string $column) => Schema::hasColumn($table, $column), ARRAY_FILTER_USE_BOTH);

        $count = (int) $where(DB::table($table))->count();

        if ($count === 0 || $values === []) {
            return;
        }

        if ($this->preview) {
            $this->anonymized[$table] = ($this->anonymized[$table] ?? 0) + $count;

            return;
        }

        try {
            DB::transaction(fn () => $where(DB::table($table))->update($values));
            $this->anonymized[$table] = ($this->anonymized[$table] ?? 0) + $count;
        } catch (QueryException $exception) {
            $this->refusal($exception);

            // The tombstone itself is not optional: leaving the identity as it
            // was would leave the person identifiable, so fail the erasure.
            if ($essential) {
                throw $exception;
            }

            $this->retained[$label ?? $table] = 'protected by the database, so left as it was';
        }
    }

    /**
     * Note the stored files behind rows about to be deleted or blanked. They are
     * removed only after the transaction commits, so a rolled-back erasure never
     * loses a file.
     *
     * @param  Closure(Builder): Builder  $where
     */
    public function collectFiles(string $table, Closure $where, string $pathColumn = 'path', ?string $diskColumn = 'disk', string $fallbackDisk = 'local'): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $pathColumn)) {
            return;
        }

        $columns = $diskColumn !== null && Schema::hasColumn($table, $diskColumn) ? [$pathColumn, $diskColumn] : [$pathColumn];

        foreach ($where(DB::table($table))->get($columns) as $row) {
            $path = $row->{$pathColumn};

            if (! is_string($path) || $path === '' || str_starts_with($path, 'erased')) {
                continue;
            }

            $this->files[] = [
                'disk' => $diskColumn !== null && isset($row->{$diskColumn}) ? (string) $row->{$diskColumn} : $fallbackDisk,
                'path' => $path,
            ];
        }
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    public function result(): ErasureResult
    {
        return new ErasureResult(
            subjectType: $this->subjectType,
            tombstone: $this->tombstone,
            preview: $this->preview,
            deleted: $this->deleted,
            anonymized: $this->anonymized,
            retained: $this->retained,
            files: array_values(array_unique($this->files, SORT_REGULAR)),
            notes: $this->notes,
        );
    }

    /**
     * The value that stands in for an erased one in this column, or false when
     * the column cannot sensibly be blanked.
     *
     * Nullable columns become null. A required JSON column becomes `{"erased":
     * true}`. A required text column becomes the tombstone reference.
     */
    protected function placeholder(string $table, string $column): mixed
    {
        $info = $this->column($table, $column);

        if ($info === null) {
            return false;
        }

        if ($info->is_nullable === 'YES') {
            return null;
        }

        return match ($info->udt_name) {
            'json' => DB::raw("'{\"erased\":true}'::json"),
            'jsonb' => DB::raw("'{\"erased\":true}'::jsonb"),
            'varchar', 'text', 'bpchar' => $this->tombstone,
            default => false,
        };
    }

    /**
     * @return object{udt_name: string, is_nullable: string}|null
     */
    protected function column(string $table, string $column): ?object
    {
        if (! array_key_exists($column, $this->columnCache[$table] ?? [])) {
            $this->columnCache[$table][$column] = DB::selectOne(
                'select udt_name, is_nullable from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
                [$table, $column],
            );
        }

        return $this->columnCache[$table][$column];
    }

    /**
     * Record something the database will not let change, without trying.
     */
    public function retain(string $what, string $reason): void
    {
        $this->retained[$what] = $reason;
    }

    protected function refusal(QueryException $exception): void
    {
        $code = (string) $exception->getCode();

        // Integrity-constraint class (foreign key, not-null, check, and the
        // append-only/locked-column guards) and raised exceptions are refusals.
        // A unique violation is a collision, and anything else a bug.
        if ($code === 'P0001' || (str_starts_with($code, '23') && $code !== '23505')) {
            return;
        }

        throw $exception;
    }
}
