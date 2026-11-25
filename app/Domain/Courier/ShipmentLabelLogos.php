<?php

namespace App\Domain\Courier;

use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Website\Models\Website;
use finfo;
use Throwable;

/**
 * Freezes a Storefront's logo for its shipping labels, and reads it back.
 *
 * A Storefront replacing its logo deletes the old file
 * (`ManageWebsiteBranding::putImage()`), so a label that only remembered the
 * path would lose its logo on the next reprint. The bytes are therefore
 * copied, at issue, to private storage under their own SHA-256 -- one copy per
 * distinct image however many labels use it, and never overwritten, because the
 * name *is* the content.
 *
 * Anything that goes wrong -- no logo, a missing file, an unreadable or
 * unexpected image -- yields no logo, and the label prints the Store name
 * instead. A label is never refused, and never shows a broken image, over a
 * logo.
 */
class ShipmentLabelLogos
{
    /** Logos above this are not copied; the label falls back to the Store name. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var array<string, string> image type => file extension */
    protected const TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    public function __construct(
        protected ManagedStorage $storage,
    ) {}

    /**
     * @return array{store_logo_disk: string, store_logo_path: string, store_logo_mime: string}|null
     */
    public function freeze(Website $website): ?array
    {
        $source = $website->logo_path;

        if ($source === null || $source === '') {
            return null;
        }

        try {
            $bytes = $this->storage->diskForPath($source, StorageVisibility::Public)->get($source);

            if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                return null;
            }

            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

            // finfo names an SVG without its XML declaration as text.
            if (in_array($mime, ['text/plain', 'text/xml', 'application/xml'], true) && str_contains($bytes, '<svg')) {
                $mime = 'image/svg+xml';
            }

            $extension = self::TYPES[$mime] ?? null;

            if ($extension === null) {
                return null;
            }

            $path = 'shipment-labels/logos/'.hash('sha256', $bytes).'.'.$extension;
            $disk = $this->storage->diskFor(StorageVisibility::Private);

            if (! $disk->exists($path)) {
                $disk->put($path, $bytes);
            }

            return [
                'store_logo_disk' => $this->storage->diskNameFor(StorageVisibility::Private),
                'store_logo_path' => $path,
                'store_logo_mime' => $mime,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The frozen logo as a data URI, embedded in the printed page so nothing
     * is fetched at print time and no file address is ever exposed. Null when
     * the label has none or it cannot be read.
     */
    public function dataUri(ShipmentLabel $label): ?string
    {
        if ($label->store_logo_disk === null || $label->store_logo_path === null || $label->store_logo_mime === null) {
            return null;
        }

        try {
            $bytes = $this->storage->resolveNamedDisk($label->store_logo_disk)->get($label->store_logo_path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        return 'data:'.$label->store_logo_mime.';base64,'.base64_encode($bytes);
    }
}
