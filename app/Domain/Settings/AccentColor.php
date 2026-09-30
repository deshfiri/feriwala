<?php

namespace App\Domain\Settings;

use InvalidArgumentException;

/**
 * The platform's accent colour: primary actions, the current navigation row,
 * focus rings and every brand tint derived from them.
 *
 * Held as a six-digit hex and nothing else, because the value is written into
 * a style attribute on every page — a format this narrow cannot carry a CSS
 * declaration, a `url()` or a closing tag, whoever managed to store it.
 *
 * The text colour laid over the accent is decided here, from WCAG contrast,
 * rather than assumed white: an administrator is free to pick a pale yellow,
 * and a white label on it would be unreadable.
 */
final class AccentColor
{
    /**
     * The shipped accent, used until an administrator picks another. Mirrors
     * `--brand-base` in `resources/css/app.css`.
     */
    public const DEFAULT_HEX = '#ca6330';

    /**
     * Label colours an accent can carry: white, or the theme's slate text.
     */
    public const LIGHT_TEXT = '#ffffff';

    public const DARK_TEXT = '#1d2630';

    /**
     * How much white dark mode mixes into the accent so it keeps contrast on a
     * near-black ground. Mirrors the `color-mix()` under `.dark` in app.css.
     */
    public const DARK_LIFT_PERCENT = 25;

    /**
     * WCAG AA for body text: below this, the accent used as text on a white
     * card is hard to read.
     */
    public const MINIMUM_TEXT_CONTRAST = 4.5;

    private function __construct(private readonly string $hex) {}

    /**
     * @throws InvalidArgumentException when the value is not `#rrggbb`
     */
    public static function fromHex(string $hex): self
    {
        if (! self::isValidHex($hex)) {
            throw new InvalidArgumentException('An accent colour must be a six-digit hex such as #ca6330.');
        }

        return new self(strtolower($hex));
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_HEX);
    }

    public static function isValidHex(string $hex): bool
    {
        // `\z`, not `$`: `$` also matches before a trailing newline.
        return preg_match('/^#[0-9A-Fa-f]{6}\z/', $hex) === 1;
    }

    public function hex(): string
    {
        return $this->hex;
    }

    public function isDefault(): bool
    {
        return $this->hex === self::DEFAULT_HEX;
    }

    /**
     * The label colour for text laid on the accent itself (primary buttons).
     */
    public function onColor(): string
    {
        return self::readableTextOn($this->channels());
    }

    /**
     * The label colour for text laid on the lifted dark-mode accent.
     */
    public function onLiftedColor(): string
    {
        return self::readableTextOn($this->liftedChannels());
    }

    /**
     * Contrast of the accent used as text on a white surface.
     */
    public function contrastOnWhite(): float
    {
        return self::contrast($this->channels(), [255, 255, 255]);
    }

    public function isReadableAsText(): bool
    {
        return $this->contrastOnWhite() >= self::MINIMUM_TEXT_CONTRAST;
    }

    /**
     * The shared contract the shell writes into `--brand-*` custom properties.
     *
     * @return array{color: string, on: string, lifted_on: string}
     */
    public function toArray(): array
    {
        return [
            'color' => $this->hex,
            'on' => $this->onColor(),
            'lifted_on' => $this->onLiftedColor(),
        ];
    }

    /**
     * @return array{int, int, int}
     */
    private function channels(): array
    {
        return [
            (int) hexdec(substr($this->hex, 1, 2)),
            (int) hexdec(substr($this->hex, 3, 2)),
            (int) hexdec(substr($this->hex, 5, 2)),
        ];
    }

    /**
     * The accent mixed with white in sRGB, as `color-mix(in srgb, …)` does.
     *
     * @return array{int, int, int}
     */
    private function liftedChannels(): array
    {
        $keep = 100 - self::DARK_LIFT_PERCENT;

        return array_map(
            fn (int $channel): int => intdiv($channel * $keep + 255 * self::DARK_LIFT_PERCENT + 50, 100),
            $this->channels(),
        );
    }

    /**
     * @param  array{int, int, int}  $background
     */
    private static function readableTextOn(array $background): string
    {
        $light = self::contrast($background, self::hexChannels(self::LIGHT_TEXT));
        $dark = self::contrast($background, self::hexChannels(self::DARK_TEXT));

        return $light >= $dark ? self::LIGHT_TEXT : self::DARK_TEXT;
    }

    /**
     * @return array{int, int, int}
     */
    private static function hexChannels(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * WCAG 2 contrast ratio between two sRGB colours.
     *
     * @param  array{int, int, int}  $first
     * @param  array{int, int, int}  $second
     */
    private static function contrast(array $first, array $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * WCAG 2 relative luminance.
     *
     * @param  array{int, int, int}  $channels
     */
    private static function luminance(array $channels): float
    {
        [$red, $green, $blue] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
