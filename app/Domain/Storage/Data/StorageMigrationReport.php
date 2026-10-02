<?php

namespace App\Domain\Storage\Data;

/**
 * The machine-readable result of one R2 migration command run
 * (beta-critical batch, Commit 5) -- counts only, never a path, a
 * credential, or a byte of file content.
 */
final class StorageMigrationReport
{
    /**
     * @param  array<string, array<string, int>>  $sources
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $sources,
    ) {}

    /**
     * @return array{mode: string, generated_at: string, sources: array<string, array<string, int>>}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'generated_at' => now()->toIso8601String(),
            'sources' => $this->sources,
        ];
    }
}
