<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Policies\CatalogPolicy;
use App\Domain\Kyc\KycDocumentStore;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
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
 */
class CatalogImageStore
{
    /**
     * The public disk, symlinked into `public/storage`.
     */
    public const DISK = 'public';

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

    /**
     * @var array<string, string>
     */
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        protected FilesystemFactory $filesystem,
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
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        /*
         * The MIME type is read from the file's own bytes rather than from what
         * the browser said it was sending, because the browser's word is the
         * attacker's word on an upload endpoint.
         */
        if (! in_array($mime, self::ACCEPTED_MIME_TYPES, true)) {
            throw CatalogRefused::imageTypeNotAccepted($mime);
        }

        if ($size > self::MAX_BYTES) {
            throw CatalogRefused::imageTooLarge($size, self::MAX_BYTES);
        }

        $path = sprintf(
            'catalog/%s/%s.%s',
            trim($folder, '/'),
            bin2hex(random_bytes(16)),
            self::EXTENSIONS[$mime],
        );

        $this->disk()->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
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
        if ($path === null || $path === '') {
            return;
        }

        $this->disk()->delete($path);
    }

    /**
     * The address a storefront renders.
     */
    public function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $this->disk()->url($path);
    }

    protected function disk(): Filesystem
    {
        return $this->filesystem->disk(self::DISK);
    }
}
