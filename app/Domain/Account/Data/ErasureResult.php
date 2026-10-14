<?php

namespace App\Domain\Account\Data;

/**
 * What an erasure did — or, for a preview, would do.
 *
 * Holds counts and table names only: never a value that was erased, so it is
 * safe to show, log and put in the audit trail.
 */
final class ErasureResult
{
    /**
     * @param  array<string, int>  $deleted  table => rows permanently removed
     * @param  array<string, int>  $anonymized  table => rows whose personal details were replaced
     * @param  array<string, string>  $retained  table => why it could not be changed, and so was left as it was
     * @param  array<int, array{disk: string, path: string}>  $files  stored files to remove once the rows are gone
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $tombstone,
        public readonly bool $preview,
        public readonly array $deleted,
        public readonly array $anonymized,
        public readonly array $retained,
        public readonly array $files,
        public readonly array $notes = [],
    ) {}

    public function totalDeleted(): int
    {
        return array_sum($this->deleted);
    }

    public function totalAnonymized(): int
    {
        return array_sum($this->anonymized);
    }

    /**
     * The shape that is safe to keep: counts and names, no paths and no values.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'tombstone' => $this->tombstone,
            'deleted' => $this->deleted,
            'anonymized' => $this->anonymized,
            'retained' => array_keys($this->retained),
            'files' => count($this->files),
        ];
    }
}
