<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Cms\Exceptions\CmsMediaRefused;
use App\Domain\Cms\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;

/**
 * Stores a CMS upload (§4, §34's media-safety requirements), following the
 * exact conventions {@see ProductMediaStore} already
 * established for the catalogue: the type is read from the file's own
 * bytes, never the browser's claim or the original filename, and the stored
 * path is random rather than derived from either.
 */
class UploadCmsMedia
{
    /**
     * The same shared 'public' disk ProductMediaStore already uses, prefixed
     * by path rather than given a dedicated disk config — one less disk to
     * keep in step for storage that has identical requirements (public,
     * local-driver, served directly).
     */
    public const DISK = 'public';

    protected const PATH_PREFIX = 'cms';

    /** @var array<int, string> */
    public const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** 5 MB: a large marketing image, not a camera RAW. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> */
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(protected FilesystemFactory $filesystem) {}

    public function handle(UploadedFile $file, ?User $actor = null): Media
    {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if (! in_array($mime, self::ALLOWED_TYPES, true)) {
            throw CmsMediaRefused::typeNotAccepted($mime);
        }

        if ($size > self::MAX_BYTES) {
            throw CmsMediaRefused::tooLarge($size, self::MAX_BYTES);
        }

        $width = null;
        $height = null;
        $dimensions = @getimagesize($file->getRealPath());

        if (is_array($dimensions)) {
            [$width, $height] = [(int) $dimensions[0], (int) $dimensions[1]];
        }

        $path = sprintf('%s/%s.%s', self::PATH_PREFIX, bin2hex(random_bytes(16)), self::EXTENSIONS[$mime]);

        $this->filesystem->disk(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        return Media::query()->create([
            'disk' => self::DISK,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'uploaded_by' => $actor?->id,
        ]);
    }
}
