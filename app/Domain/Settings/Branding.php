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

    /**
     * The setting that holds the button colour as `#rrggbb`. Null means buttons
     * follow the accent.
     */
    public const BUTTON_SETTING = 'branding.button_color';

    /**
     * The setting that holds the light-mode page background as `#rrggbb`. Null
     * means the shipped white.
     */
    public const BACKGROUND_SETTING = 'branding.background_color';

    /**
     * The setting that holds the current navigation link colour as `#rrggbb`.
     * Null means the link follows the accent.
     */
    public const NAV_SETTING = 'branding.nav_color';

    public function __construct(
        protected SettingsRepository $settings,
        protected ManagedStorage $storage,
    ) {}

    /**
     * The shared contract: addresses only, never storage paths. `accent` is
     * null while the shipped accent is in use, so the stylesheet's own value
     * stands and nothing is written into the page.
     *
     * @return array{logo_url: string, favicon_url: string, favicon_type: string, accent: array{color: string, on: string, lifted_on: string}|null, button: array{color: string, on: string, lifted_on: string}|null, nav: array{color: string, on: string, lifted_on: string}|null, background: string|null}
     */
    public function toArray(): array
    {
        $accent = $this->customAccent();

        return [
            'logo_url' => $this->url(BrandingAsset::Logo),
            'favicon_url' => $this->url(BrandingAsset::Favicon),
            'favicon_type' => $this->faviconType(),
            'accent' => $accent?->toArray(),
            'button' => $this->customButton()?->toArray(),
            'nav' => $this->customNav()?->toArray(),
            'background' => $this->customBackground()?->hex(),
        ];
    }

    /**
     * The colour of the current navigation link: the chosen one, or the accent.
     */
    public function nav(): AccentColor
    {
        return $this->customNav() ?? $this->accent();
    }

    /**
     * The chosen navigation colour, when one is stored and well formed.
     */
    public function customNav(): ?AccentColor
    {
        try {
            $value = $this->settings->get(self::NAV_SETTING);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || ! AccentColor::isValidHex($value)) {
            return null;
        }

        return AccentColor::fromHex($value);
    }

    /**
     * The chosen light-mode page background, when one is stored, well formed
     * and still readable under the theme's dark text. Anything else falls back
     * to the shipped white, so a bad stored value can never make text vanish.
     */
    public function customBackground(): ?AccentColor
    {
        try {
            $value = $this->settings->get(self::BACKGROUND_SETTING);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || ! AccentColor::isValidHex($value)) {
            return null;
        }

        $background = AccentColor::fromHex($value);

        return $background->isReadableUnderDarkText() && ! $background->isWhite() ? $background : null;
    }

    /**
     * The colour buttons and other primary controls use: the chosen one, or
     * the accent when none has been set.
     */
    public function button(): AccentColor
    {
        return $this->customButton() ?? $this->accent();
    }

    /**
     * The chosen button colour, when one is stored and well formed. Unlike the
     * accent, a value equal to the shipped accent is still a choice: it keeps
     * buttons put if the accent later changes.
     */
    public function customButton(): ?AccentColor
    {
        try {
            $value = $this->settings->get(self::BUTTON_SETTING);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || ! AccentColor::isValidHex($value)) {
            return null;
        }

        return AccentColor::fromHex($value);
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
     * Whether a value is a path this feature wrote, with nothing that could
     * climb out of the folder and no embedded data.
     *
     * Two shapes are accepted: `branding/<asset>/<name>.<ext>` (the shape
     * {@see StoreManagedFile} gives every file -- `<purpose>/<random>.<ext>`
     * -- since Commit 4 of the beta-critical batch) and the older
     * `branding/<asset>-<name>.<ext>` a file uploaded before that batch may
     * still carry. Narrowing this to only the new shape would silently
     * revert an administrator's already-uploaded logo or favicon to the
     * shipped default the next time this is checked -- accepting both is
     * what keeps an old upload showing until it is next replaced.
     */
    public static function isManagedPath(string $path): bool
    {
        return preg_match('#^'.self::FOLDER.'/[a-z]+[/-][A-Za-z0-9_-]+\.(png|jpg|webp|ico)$#', $path) === 1;
    }

    public function disk(): Filesystem
    {
        return $this->storage->diskFor(StorageVisibility::Public);
    }
}
