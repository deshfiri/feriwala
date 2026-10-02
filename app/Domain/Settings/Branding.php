<?php

namespace App\Domain\Settings;

use App\Domain\Settings\Enums\BrandingAsset;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\ManagedStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * The brand images every screen shows — resolved, never raw.
 *
 * The one place a logo or browser icon address is decided, and the shared
 * branding prop is built from it, so the sidebar, the sign-in pages, the public
 * page and the document head cannot disagree.
 *
 * Only ever hands out an address a browser can load. A stored path is used only
 * when it is one of ours — under `branding/` on the public disk — and the file is
 * actually there. Anything else falls back to the file shipped in `public/`: a
 * setting nobody has configured, a file removed from the disk, a value that is
 * not a managed path at all, or a settings store that cannot be reached. A
 * missing logo is an inconvenience; a page that fails to render because of one
 * is an outage.
 */
class Branding
{
    /**
     * The disk brand images are written to while Cloudflare R2 is switched
     * off -- the configured public disk, symlinked into `public/storage`.
     * {@see disk()} resolves through {@see ManagedStorage} instead of this
     * constant directly, so brand images move to R2 the moment it is
     * switched on.
     */
    public const DISK = 'public';

    /**
     * Where under the disk they live. A stored path outside it is not served.
     */
    public const FOLDER = 'branding';

    /**
     * The setting that holds the accent colour as `#rrggbb`. Null means the
     * shipped accent.
     */
    public const ACCENT_SETTING = 'branding.accent_color';

    public function __construct(
        protected SettingsRepository $settings,
        protected ManagedStorage $storage,
    ) {}

    /**
     * The shared contract: addresses only, never storage paths. `accent` is
     * null while the shipped accent is in use, so the stylesheet's own value
     * stands and nothing is written into the page.
     *
     * @return array{logo_url: string, favicon_url: string, favicon_type: string, accent: array{color: string, on: string, lifted_on: string}|null}
     */
    public function toArray(): array
    {
        $accent = $this->customAccent();

        return [
            'logo_url' => $this->url(BrandingAsset::Logo),
            'favicon_url' => $this->url(BrandingAsset::Favicon),
            'favicon_type' => $this->faviconType(),
            'accent' => $accent?->toArray(),
        ];
    }

    /**
     * The accent every screen uses: the chosen one, or the shipped default.
     */
    public function accent(): AccentColor
    {
        return $this->customAccent() ?? AccentColor::default();
    }

    /**
     * The chosen accent, when one is stored and well formed. Anything else —
     * nothing chosen, a malformed value, an unreachable settings store — falls
     * back to the stylesheet's own accent rather than breaking the page.
     */
    public function customAccent(): ?AccentColor
    {
        try {
            $value = $this->settings->get(self::ACCENT_SETTING);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || ! AccentColor::isValidHex($value)) {
            return null;
        }

        $accent = AccentColor::fromHex($value);

        return $accent->isDefault() ? null : $accent;
    }

    public function url(BrandingAsset $asset): string
    {
        $path = $this->storedPath($asset);

        if ($path === null) {
            return $asset->defaultUrl();
        }

        return $this->storage->urlForPath($path, StorageVisibility::Public) ?? $asset->defaultUrl();
    }

    public function isCustom(BrandingAsset $asset): bool
    {
        return $this->storedPath($asset) !== null;
    }

    /**
     * The icon's MIME type, from the stored file's extension. The default is the
     * shipped SVG.
     */
    public function faviconType(): string
    {
        $path = $this->storedPath(BrandingAsset::Favicon);

        if ($path === null) {
            return 'image/svg+xml';
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'image/svg+xml',
        };
    }

    /**
     * The stored path, when it is a managed one whose file is present.
     */
    public function storedPath(BrandingAsset $asset): ?string
    {
        try {
            $path = $this->settings->get($asset->setting());

            if (! is_string($path) || ! self::isManagedPath($path)) {
                return null;
            }

            return $this->storage->existsForPath($path, StorageVisibility::Public) ? $path : null;
        } catch (Throwable) {
            // The settings store or the disk is unavailable: show the defaults.
            return null;
        }
    }

    /**
     * Whether a value is a path this feature wrote: `branding/<asset>/<name>.<ext>`
     * (the shape {@see StoreManagedFile} gives
     * every file -- `<purpose>/<random>.<ext>`), with nothing that could
     * climb out of the folder and no embedded data.
     */
    public static function isManagedPath(string $path): bool
    {
        return preg_match('#^'.self::FOLDER.'/[a-z]+/[A-Za-z0-9_-]+\.(png|jpg|webp|ico)$#', $path) === 1;
    }

    public function disk(): Filesystem
    {
        return $this->storage->diskFor(StorageVisibility::Public);
    }
}
