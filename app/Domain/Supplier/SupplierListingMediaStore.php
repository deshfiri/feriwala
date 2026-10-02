<?php

namespace App\Domain\Supplier;

use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Supplier\Actions\ManageSupplierListingMedia;
use App\Domain\Supplier\Models\SupplierProductListing;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Supplier listing images, on the private `supplier-media` disk (Supplier
 * Bulk Product Listing batch) -- the same validation shape as
 * {@see ProductMediaStore}, since the batch's spec asks
 * for "secure storage using existing media conventions," but never on the
 * `public` disk: a listing is a pre-approval proposal, not a published
 * storefront asset, and it may carry Supplier-identifying context (D25).
 *
 * Who may **put** a file here is enforced by the calling action
 * ({@see ManageSupplierListingMedia}), not by
 * this class -- exactly the division `ProductMediaStore` already draws.
 *
 * Resolves through {@see ManagedStorage::diskForLocal()}: switches to
 * Cloudflare R2 the moment that is configured, exactly like every other
 * surface, but keeps landing on its own dedicated `supplier-media` disk
 * (never the generic shared `private` one) while R2 is off, so existing
 * rows and on-disk layout are untouched.
 */
class SupplierListingMediaStore
{
    public const DISK = 'supplier-media';

    /** @var array<int, string> */
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * 5 MB: a large product photograph, not a camera RAW -- the same limit
     * {@see ProductMediaStore::IMAGE_MAX_BYTES} uses.
     */
    public const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> */
    protected const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        protected ManagedStorage $storage,
    ) {}

    /**
     * The largest file the server will actually take, never above what PHP
     * itself accepts for one upload.
     */
    public static function maxBytes(): int
    {
        return min(self::IMAGE_MAX_BYTES, self::serverUploadLimit());
    }

    public static function serverUploadLimit(): int
    {
        $limits = array_filter([
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ], fn (int $bytes) => $bytes > 0);

        return $limits === [] ? PHP_INT_MAX : min($limits);
    }

    /**
     * Store an upload against a listing.
     *
     * @return array{path: string, disk: string, mime_type: string, size_bytes: int, width: int|null, height: int|null}
     */
    public function store(UploadedFile $file, SupplierProductListing $listing): array
    {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        if (! in_array($mime, self::IMAGE_TYPES, true)) {
            throw new InvalidArgumentException("Images of type [{$mime}] are not accepted.");
        }

        if ($size > self::maxBytes()) {
            throw new InvalidArgumentException(sprintf(
                'This image is too large. The limit is %d MB.',
                (int) (self::maxBytes() / (1024 * 1024)),
            ));
        }

        $dimensions = @getimagesize($file->getRealPath());
        [$width, $height] = is_array($dimensions) ? [(int) $dimensions[0], (int) $dimensions[1]] : [null, null];

        $path = sprintf(
            'supplier-listings/%s/%s.%s',
            $listing->public_id,
            bin2hex(random_bytes(16)),
            self::EXTENSIONS[$mime],
        );

        $this->storage->diskForLocal(self::DISK)->put($path, (string) file_get_contents($file->getRealPath()));

        return [
            'path' => $path,
            'disk' => $this->storage->diskNameForLocal(self::DISK),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * `$disk` should be the row's own stored disk when the caller has it.
     */
    public function delete(?string $path, ?string $disk = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $this->storage->resolveNamedDisk($disk ?? self::DISK)->delete($path);
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
