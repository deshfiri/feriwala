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
        if ($this->r2Settings->isAvailable()) {
            return $this->r2->disk($this->r2Settings->toR2ManagerCredentials());
        }

        return $this->filesystem->disk($visibility === StorageVisibility::Public ? 'public' : 'private');
    }

    public function diskNameFor(StorageVisibility $visibility): string
    {
        if ($this->r2Settings->isAvailable()) {
            return 'r2';
        }

        return $visibility === StorageVisibility::Public ? 'public' : 'private';
    }

    /**
     * The disk a previously stored file actually lives on -- its own `disk`
     * column, never whatever is active today, so a file written before a
     * later switch to (or away from) R2 is still read from where it was
     * actually put.
     */
    public function diskForFile(StoredFile $file): Filesystem
    {
        if ($file->disk === 'r2') {
            return $this->r2->disk($this->r2Settings->toR2ManagerCredentials());
        }

        return $this->filesystem->disk($file->disk);
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
