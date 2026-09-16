<?php

namespace App\Domain\Website\Enums;

/**
 * The storefront presets a partner may choose between (§16.3).
 *
 * A closed list, because a theme is a layout the storefront application ships
 * rather than a stylesheet a partner uploads: a free-form value here would be a
 * name no storefront knows how to render.
 *
 * This is not the ERP's appearance setting. Light, Dark and System are how a
 * person reads their own admin; these are how their customers see their shop.
 */
enum WebsiteTheme: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Minimal = 'minimal';

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Classic',
            self::Modern => 'Modern',
            self::Minimal => 'Minimal',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $theme) => $theme->value, self::cases());
    }
}
