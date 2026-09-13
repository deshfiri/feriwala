<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Exceptions\CatalogRefused;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;

/**
 * Product images and videos on the public disk (§11.1).
 *
 * Public for the same reason brand logos are: every partner storefront renders
 * these, and proxying each request through an authorisation check would slow a
 * catalogue page down for a file that is public by intent. Who may **put** a file
 * here is not relaxed — every route that reaches this class asks the catalogue
 * policy first (§12).
 *
 * The type is read from the file's bytes, never from its name or the browser's
 * word, and checked again here after the form request has checked it, so a
 * caller that skipped validation still cannot write an unchecked file. SVG is
 * excluded: it is a document that can carry script served from our own origin.
 *
 * **The enforced size is never above what PHP will accept.** A cap of 50 MB on a
 * server whose `upload_max_filesize` is 2 MB would promise something the upload
 * fails before validation ever sees it, so {@see maxBytesFor()} answers with
 * whichever is smaller and the form's help text reads the same figure.
 */
class ProductMediaStore
{
    public const DISK = 'public';

    /** @var array<int, string> */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var array<int, string> */
    public const VIDEO_TYPES = ['video/mp4', 'video/webm'];

    /**
     * 5 MB: a large product photograph, not a camera RAW.
     */
    public const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * 50 MB: a short product clip. Anything longer belongs on a video host.
     */
    public const VIDEO_MAX_BYTES = 50 * 1024 * 1024;

    /** @var array<string, string> */
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    public function __construct(
        protected FilesystemFactory $filesystem,
    ) {}

    /**
     * Which kind of media a MIME type is, or null when it is neither.
     */
    public static function typeOf(string $mime): ?string
    {
        return match (true) {
            in_array($mime, self::IMAGE_TYPES, true) => ProductMedia::TYPE_IMAGE,
            in_array($mime, self::VIDEO_TYPES, true) => ProductMedia::TYPE_VIDEO,
            default => null,
        };
    }

    /**
     * The largest file of this type the server will actually take.
     */
    public static function maxBytesFor(string $type): int
    {
        $cap = $type === ProductMedia::TYPE_VIDEO ? self::VIDEO_MAX_BYTES : self::IMAGE_MAX_BYTES;

        return min($cap, self::serverUploadLimit());
    }

    /**
     * What PHP itself accepts in one upload: the smaller of the file and the
     * whole-request limits. Zero in either means that one is unlimited.
     */
    public static function serverUploadLimit(): int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], fn (int $bytes) => $bytes > 0);

        return $limits === [] ? PHP_INT_MAX : min($limits);
    }

    /**
     * Store an upload against a product.
     *
     * @return array{type: string, path: string, mime_type: string, size_bytes: int, width: int|null, height: int|null}
     *
     * @throws CatalogRefused
     */
    public function store(UploadedFile $file, Product $product): array
    {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $type = self::typeOf($mime);

        if ($type === null) {
            throw CatalogRefused::mediaTypeNotAccepted($mime);
        }

        if ($size > self::maxBytesFor($type)) {
            throw CatalogRefused::mediaTooLarge($type, $size, self::maxBytesFor($type));
        }

        $width = null;
        $height = null;

        if ($type === ProductMedia::TYPE_IMAGE) {
            $dimensions = @getimagesize($file->getRealPath());

            if (is_array($dimensions)) {
                [$width, $height] = [(int) $dimensions[0], (int) $dimensions[1]];
            }
        }

        // Random names under the product: nothing of the original filename is
        // worth keeping, and a predictable path is one somebody can try.
        $path = sprintf(
            'catalog/products/%s/%s.%s',
            $product->public_id,
            bin2hex(random_bytes(16)),
            self::EXTENSIONS[$mime],
        );

        $this->disk()->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'type' => $type,
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Remove a stored file. Silent about one already gone.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->disk()->delete($path);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }

    protected function disk(): Filesystem
    {
        return $this->filesystem->disk(self::DISK);
    }

    protected static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
