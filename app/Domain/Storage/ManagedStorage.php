<?php

namespace App\Domain\Storage;

use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Models\StoredFile;
use App\Integrations\Storage\R2Manager;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Resolves every managed file to the disk that should actually hold it
 * today (beta-critical batch, Commit 4).
 *
 * The one fork point between "the local `public`/`private` disks" and
 * "Cloudflare R2" -- a caller asks for a {@see StorageVisibility}, not a disk
 * name, so switching R2 on moves every surface that writes through this
 * class without any of them being told how storage is configured.
 *
 * Deliberately does not migrate anything already written to the disk it is
 * no longer resolving to -- a file stored locally before R2 was switched on
 * stays on the local disk until the migration command (Commit 5) moves it.
 */
class ManagedStorage
{
    public function __construct(
        protected R2StorageSettings $r2Settings,
        protected R2Manager $r2,
        protected FilesystemFactory $filesystem,
    ) {}

    public function diskFor(StorageVisibility $visibility): Filesystem
    {
        return $this->diskForLocal($visibility === StorageVisibility::Public ? 'public' : 'private');
    }

    public function diskNameFor(StorageVisibility $visibility): string
    {
        return $this->diskNameForLocal($visibility === StorageVisibility::Public ? 'public' : 'private');
    }

    /**
     * Today's write/read target for a surface that keeps its own dedicated
     * local disk name -- `supplier-media`, `kyc` -- rather than the generic
     * `public`/`private` pair. R2, when switched on, still takes over for
     * these exactly as it does for everything else; when it is off, each
     * surface keeps landing on the specific disk it always has, so existing
     * rows, tests and on-disk layouts for these surfaces are untouched.
     */
    public function diskForLocal(string $localDiskName): Filesystem
    {
        if ($this->r2Settings->isAvailable()) {
            return $this->r2->disk($this->r2Settings->toR2ManagerCredentials());
        }

        return $this->filesystem->disk($localDiskName);
    }

    public function diskNameForLocal(string $localDiskName): string
    {
        return $this->r2Settings->isAvailable() ? 'r2' : $localDiskName;
    }

    /**
     * The disk a previously stored file actually lives on -- its own `disk`
     * column, never whatever is active today, so a file written before a
     * later switch to (or away from) R2 is still read from where it was
     * actually put.
     */
    public function diskForFile(StoredFile $file): Filesystem
    {
        return $this->resolveNamedDisk($file->disk);
    }

    /**
     * The disk behind any stored `disk` name a table already tracks per row
     * -- `ProductMedia`, `SupplierListingMedia`, `KycDocument` and
     * `SupplierKycDocument` all keep one of these on every row, exactly as
     * {@see StoredFile} does. `'r2'` is never a disk registered in
     * `config/filesystems.php` (it has no single static configuration), so
     * every caller that resolves a disk by a stored name -- not only
     * {@see StoredFile} -- must come through here rather than
     * `Storage::disk()` directly.
     */
    public function resolveNamedDisk(string $diskName): Filesystem
    {
        if ($diskName === 'r2') {
            return $this->r2->disk($this->r2Settings->toR2ManagerCredentials());
        }

        return $this->filesystem->disk($diskName);
    }

    /**
     * The disk a bare path string actually lives on, for a surface that
     * never kept its own `disk` column (a storefront's logo/banner, the
     * platform's own branding, a brand/category image).
     *
     * Looks up a {@see StoredFile} row for the path first -- present for
     * anything written since this abstraction existed -- and uses *its*
     * disk, so a file already migrated to R2 is still found there. Only
     * when no row is tracking the path does this fall back to whichever
     * disk `$visibility` resolves to today, which is correct for a file
     * older than this abstraction that has not been migrated (and will not
     * be: {@see MigrationSources} does not cover a bare
     * path with no recorded size or checksum to verify against).
     */
    public function diskForPath(string $path, StorageVisibility $visibility): Filesystem
    {
        $tracked = StoredFile::query()->where('path', $path)->first();

        return $tracked !== null ? $this->diskForFile($tracked) : $this->diskFor($visibility);
    }

    /**
     * The address a public file is rendered from, given only a bare path
     * string. See {@see diskForPath()}.
     */
    public function urlForPath(?string $path, StorageVisibility $visibility): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            return $this->diskForPath($path, $visibility)->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a bare path string's file still physically exists. See
     * {@see diskForPath()}.
     */
    public function existsForPath(?string $path, StorageVisibility $visibility): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        try {
            return $this->diskForPath($path, $visibility)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The address a public file is rendered from. Null for a private file --
     * callers stream those through their own authorised controller instead,
     * the same convention `KycDocumentStore`/`SupplierListingMediaStore`
     * already hold to.
     */
    public function url(StoredFile $file): ?string
    {
        if ($file->visibility !== StorageVisibility::Public) {
            return null;
        }

        try {
            return $this->diskForFile($file)->url($file->path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A time-limited link to a private file, when the disk it lives on can
     * make one (R2 and other S3-compatible disks can; the local `private`
     * disk cannot, and returns null -- stream it through a controller
     * instead).
     */
    public function temporaryUrl(StoredFile $file): ?string
    {
        if ($file->visibility !== StorageVisibility::Private) {
            return null;
        }

        try {
            return $this->diskForFile($file)->temporaryUrl(
                $file->path,
                now()->addMinutes($this->r2Settings->signedUrlExpiryMinutes()),
            );
        } catch (Throwable) {
            return null;
        }
    }
}
