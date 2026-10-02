<?php

namespace App\Domain\Storage\Data;

/**
 * What happened to one row during the R2 migration command (beta-critical
 * batch, Commit 5) -- never the file's bytes, never a credential, so this is
 * safe to fold straight into the command's machine-readable report.
 */
final class MigrationOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly int $id,
    ) {}

    public static function alreadyOnR2(int $id): self
    {
        return new self('already_on_r2', $id);
    }

    public static function migrated(int $id): self
    {
        return new self('migrated', $id);
    }

    public static function missingLocalFile(int $id): self
    {
        return new self('missing_local_file', $id);
    }

    public static function verificationFailed(int $id): self
    {
        return new self('verification_failed', $id);
    }

    public static function verified(int $id): self
    {
        return new self('verified', $id);
    }

    public static function missingRemoteFile(int $id): self
    {
        return new self('missing_remote_file', $id);
    }

    public static function skippedNotOnR2(int $id): self
    {
        return new self('skipped_not_on_r2', $id);
    }
}
