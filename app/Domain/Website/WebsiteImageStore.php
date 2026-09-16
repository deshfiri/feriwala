<?php

namespace App\Domain\Website;

use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Website\Exceptions\WebsiteRefused;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
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
 */
class WebsiteImageStore
{
    /** The public disk, symlinked into `public/storage`. */
    public const DISK = 'public';

    /** 2 MB for a logo, and the same again for a banner. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
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
     * Store an image for one website and return the path to record.
     *
     * @param  string  $websiteId  the website's public identifier, never its key
     * @param  string  $asset  `logo` or `banner`
     *
     * @throws WebsiteRefused
     */
    public function store(UploadedFile $file, string $websiteId, string $asset): string
    {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, self::ACCEPTED_MIME_TYPES, true)) {
            throw WebsiteRefused::imageTypeNotAccepted();
        }

        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw WebsiteRefused::imageTooLarge();
        }

        $path = sprintf(
            'websites/%s/%s-%s.%s',
            $websiteId,
            $asset,
            bin2hex(random_bytes(12)),
            self::EXTENSIONS[$mime],
        );

        $this->disk()->put($path, (string) file_get_contents($file->getRealPath()));

        return $path;
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
        if ($path === null || $path === '') {
            return;
        }

        $this->disk()->delete($path);
    }

    /**
     * The address a storefront renders. Never a storage path.
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
