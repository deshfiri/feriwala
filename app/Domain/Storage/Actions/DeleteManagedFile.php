<?php

namespace App\Domain\Storage\Actions;

use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Storage\Models\StoredFile;
use RuntimeException;

/**
 * Remove a managed file's bytes and its metadata row together
 * (beta-critical batch, Commit 4).
 *
 * Refuses while the file is still within its own retention window -- a
 * caller that needs to replace a retained file should wait, not delete
 * early because a request happened to come in.
 *
 * @throws RuntimeException when the file is still within its retention window
 */
class DeleteManagedFile
{
    public function __construct(
        protected ManagedStorage $storage,
    ) {}

    public function handle(StoredFile $file): void
    {
        if ($file->isRetained()) {
            throw new RuntimeException(
                "This file is retained until {$file->retain_until?->toDateTimeString()} and cannot be deleted yet."
            );
        }

        $this->storage->diskForFile($file)->delete($file->path);

        $file->delete();
    }

    /**
     * Remove by path rather than by row -- the replace-then-cleanup shape
     * every existing `*Store` class already uses.
     *
     * A path with no tracked row is a file stored before this abstraction
     * existed: `$fallbackVisibility`, when given, deletes it directly off
     * the disk that visibility resolves to, so an old, untracked file still
     * gets cleaned up rather than left orphaned forever. Omit it only when
     * every file at this path is guaranteed to have been written through
     * {@see StoreManagedFile}.
     */
    public function forPath(?string $path, ?StorageVisibility $fallbackVisibility = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $file = StoredFile::query()->where('path', $path)->first();

        if ($file !== null) {
            $this->handle($file);

            return;
        }

        if ($fallbackVisibility !== null) {
            $this->storage->diskFor($fallbackVisibility)->delete($path);
        }
    }
}
