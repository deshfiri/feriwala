<?php

namespace App\Domain\Settings\Enums;

/**
 * The two brand images an administrator can replace.
 *
 * Each knows where its path is recorded, what it falls back to, which file types
 * are safe to accept, and how large a file may be. SVG is deliberately absent
 * from both: it is a document that can carry script, and a scriptable file
 * served from our own origin is stored cross-site scripting.
 */
enum BrandingAsset: string
{
    case Logo = 'logo';
    case Favicon = 'favicon';

    /**
     * The setting that holds the stored path. Null means the shipped default.
     */
    public function setting(): string
    {
        return "branding.{$this->value}_path";
    }

    /**
     * The file shipped in `public/`, used until something is uploaded and
     * whenever the uploaded file cannot be served.
     */
    public function defaultUrl(): string
    {
        return match ($this) {
            self::Logo => '/logo.png',
            self::Favicon => '/favicon.svg',
        };
    }

    /**
     * MIME types read from the file's own bytes, mapped to the extension stored.
     *
     * @return array<string, string>
     */
    public function acceptedTypes(): array
    {
        return match ($this) {
            self::Logo => [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ],
            self::Favicon => [
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/x-icon' => 'ico',
                'image/vnd.microsoft.icon' => 'ico',
            ],
        };
    }

    /**
     * The largest file accepted, in kilobytes. A logo is a picture; a browser
     * icon is a few hundred pixels at most.
     */
    public function maxKilobytes(): int
    {
        return match ($this) {
            self::Logo => 2048,
            self::Favicon => 512,
        };
    }
}
