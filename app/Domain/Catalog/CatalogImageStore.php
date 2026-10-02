<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Kyc\KycDocumentStore;
use App\Domain\Storage\Actions\DeleteManagedFile;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Storage\ManagedStorage;
use Illuminate\Http\UploadedFile;

/**
 * Catalogue imagery: brand logos and category tiles (§11.3).
 *
 * Public on purpose, and the opposite of {@see KycDocumentStore}
 * for a reason worth stating. A KYC document is somebody's identity and is read
 * only through a controller that checks permission and records the access. A
 * brand mark is meant to be rendered by every partner storefront — putting it
 * behind an authorisation check would mean proxying every request for a file
 * that is public by intent, and would make a catalogue page slower for nothing.
 *
 * What is **not** relaxed is who may put a file here. Authorisation is the
 * controller's, through {@see CatalogPolicy}, and no
 * route reaches this class without passing it — §12 makes catalogue authorship a
 * platform privilege, and that includes its imagery.
 *
 * Size and type are checked twice: once in the form request, so the person gets
 * a field-level message, and once here, so a caller that forgot cannot write an
 * unchecked file. The second check is the one that matters, because it is the
 * one a future controller cannot skip.
 *
 * Names are random. An original filename can carry anything — a path fragment, a
 * second extension, somebody's name — and none of it is worth keeping for a
 * logo.
 *
 * Writes through {@see StoreManagedFile} (connecting every remaining upload
 * surface to the shared storage abstraction): the public contract is
 * unchanged -- still a plain path string -- but the file now also carries a
 * `stored_files` row with its mime type, size and checksum, and resolves to
 * whichever disk {@see ManagedStorage} currently targets.
 */
class CatalogImageStore
{
    /**
     * 2 MB. Generous for a logo or a category tile and small enough that an
     * upload cannot be used to fill a volume.
     */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * The three formats a browser renders without a plugin.
     *
     * SVG is deliberately absent: it is a document that can carry script, and a
     * scriptable file served from our own origin is a stored cross-site
     * scripting hole with a picture frame around it.
     *
     * @var array<int, string>
     */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        protected StoreManagedFile $storeFile,
        protected DeleteManagedFile $deleteFile,
        protected ManagedStorage $storage,
    ) {}

    /**
     * Store an image and return the path to record.
     *
     * @param  string  $folder  where under the disk it belongs, e.g. `brands`
     *
     * @throws CatalogRefused
     */
    public function store(UploadedFile $file, string $folder): string
    {
        try {
            $stored = $this->storeFile->handle(
                file: $file,
                purpose: 'catalog/'.trim($folder, '/'),
                visibility: StorageVisibility::Public,
                allowedMimeTypes: self::ACCEPTED_MIME_TYPES,
                maxBytes: self::MAX_BYTES,
            );
        } catch (UnacceptableFile $exception) {
            throw $exception->reason === UnacceptableFile::TOO_LARGE
                ? CatalogRefused::imageTooLarge((int) $file->getSize(), self::MAX_BYTES)
                : CatalogRefused::imageTypeNotAccepted((string) $file->getMimeType());
        }

        return $stored->path;
    }

    /**
     * Remove a stored image.
     *
     * Silent about a path that is already gone. This is called when a logo is
     * replaced or a brand removed, and a missing file at that point is the
     * desired state rather than a fault worth failing the request over.
     */
    public function delete(?string $path): void
    {
        $this->deleteFile->forPath($path, StorageVisibility::Public);
    }

    /**
     * The address a storefront renders.
     */
    public function url(?string $path): ?string
    {
        return $this->storage->urlForPath($path, StorageVisibility::Public);
    }
}
