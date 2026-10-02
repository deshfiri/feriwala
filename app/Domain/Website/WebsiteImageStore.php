<?php

namespace App\Domain\Website;

use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Storage\Actions\DeleteManagedFile;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Website\Exceptions\WebsiteRefused;
use Illuminate\Http\UploadedFile;

/**
 * A storefront's logo and banner (§16.3, P5-12).
 *
 * Public by intent, like {@see CatalogImageStore}: these are rendered by the
 * partner's own customers on a public shop, so putting them behind an
 * authorisation check would mean proxying every request for a file that is meant
 * to be seen.
 *
 * What is not relaxed is who may write one. Authorisation belongs to the
 * controller, through the website policy, and the file itself is checked here
 * as well — type from the file's own bytes rather than from what the browser
 * said, and a size cap — so a caller that forgot cannot store an unchecked file.
 *
 * SVG is deliberately absent from the accepted types: it is a document that can
 * carry script, and a scriptable file served from our own origin is a stored
 * cross-site scripting hole with a picture frame around it.
 *
 * Names are random. An original filename can carry a path fragment, a second
 * extension or somebody's name, and none of it is worth keeping for a logo.
 *
 * Writes through {@see StoreManagedFile} (beta-critical batch, Commit 4): the
 * public contract here is unchanged -- a caller still gets back the same kind
 * of path string it always did -- but the file now also carries a
 * `stored_files` row with its mime type, size and checksum, and resolves to
 * whichever disk {@see ManagedStorage} currently targets (the local `public`
 * disk today, Cloudflare R2 once that is switched on) rather than a disk name
 * hardcoded here.
 */
class WebsiteImageStore
{
    /** 2 MB for a logo, and the same again for a banner. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @var array<int, string>
     */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        protected StoreManagedFile $storeFile,
        protected DeleteManagedFile $deleteFile,
        protected ManagedStorage $storage,
    ) {}

    /**
     * Store an image for one website and return the path to record.
     *
     * @param  string  $websiteId  the website's public identifier, never its key
     * @param  string  $asset  `logo` or `banner`
     *
     * @throws WebsiteRefused
     */
    public function store(UploadedFile $file, string $websiteId, string $asset): string
    {
        try {
            $stored = $this->storeFile->handle(
                file: $file,
                purpose: "websites/{$websiteId}/{$asset}",
                visibility: StorageVisibility::Public,
                allowedMimeTypes: self::ACCEPTED_MIME_TYPES,
                maxBytes: self::MAX_BYTES,
            );
        } catch (UnacceptableFile $exception) {
            throw $exception->reason === UnacceptableFile::TOO_LARGE
                ? WebsiteRefused::imageTooLarge()
                : WebsiteRefused::imageTypeNotAccepted();
        }

        return $stored->path;
    }

    /**
     * Remove a stored image.
     *
     * Silent about a path that is already gone: this runs when an image is
     * replaced, and a missing file at that point is the desired state rather
     * than a fault worth failing the request over.
     */
    public function delete(?string $path): void
    {
        $this->deleteFile->forPath($path, StorageVisibility::Public);
    }

    /**
     * The address a storefront renders. Never a storage path.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $this->storage->diskFor(StorageVisibility::Public)->url($path);
    }
}
