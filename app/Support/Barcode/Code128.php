<?php

namespace App\Support\Barcode;

use InvalidArgumentException;

/**
 * Code 128 barcodes, drawn as SVG, for shipping labels.
 *
 * Code 128 because every courier scanner reads it and it carries letters and
 * digits densely. Set C (two digits per symbol) is used for an all-digit value
 * of even length -- a numeric consignment id -- and Set B for anything else
 * printable. The check symbol is the standard weighted modulo 103.
 *
 * Bars are sized in whole printer dots: on a 203 DPI thermal head a module is
 * three dots wide where the label has room, two where it does not, so every
 * bar edge falls on a dot boundary and nothing is resampled. The drawing is
 * geometry only; no part of the encoded value is written into the SVG as text.
 */
class Code128
{
    /** A common thermal-label resolution, in dots per inch. */
    public const PRINTER_DPI = 203;

    /** Clear space required either side of the symbol, in modules. */
    public const QUIET_ZONE = 10;

    protected const START_B = 104;

    protected const START_C = 105;

    protected const STOP = 106;

    /**
     * Bar and space widths, in modules, for symbol values 0-106.
     *
     * @var array<int, string>
     */
    protected const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /**
     * Whether a value can be encoded: printable ASCII only.
     */
    public static function canEncode(string $value): bool
    {
        return $value !== '' && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /**
     * The symbol values, start and check symbol included, stop excluded.
     *
     * @return list<int>
     */
    public static function symbols(string $value): array
    {
        if (! self::canEncode($value)) {
            throw new InvalidArgumentException('Code 128 encodes printable ASCII only.');
        }

        if (preg_match('/^\d+$/', $value) === 1 && strlen($value) % 2 === 0 && strlen($value) >= 4) {
            $symbols = [self::START_C];

            foreach (str_split($value, 2) as $pair) {
                $symbols[] = (int) $pair;
            }
        } else {
            $symbols = [self::START_B];

            foreach (str_split($value) as $character) {
                $symbols[] = ord($character) - 32;
            }
        }

        $checksum = $symbols[0];

        foreach (array_slice($symbols, 1) as $position => $symbol) {
            $checksum += ($position + 1) * $symbol;
        }

        $symbols[] = $checksum % 103;

        return $symbols;
    }

    /**
     * Bar and space widths in modules, alternating and starting with a bar,
     * quiet zones excluded.
     *
     * @return list<int>
     */
    public static function widths(string $value): array
    {
        $widths = [];

        foreach ([...self::symbols($value), self::STOP] as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $width) {
                $widths[] = (int) $width;
            }
        }

        return $widths;
    }

    /**
     * The symbol's full width in modules, quiet zones included.
     */
    public static function modules(string $value): int
    {
        return array_sum(self::widths($value)) + 2 * self::QUIET_ZONE;
    }

    /**
     * The widest whole-dot module that fits `$maxWidthInches`: three dots, then
     * two. Null when even two-dot modules would not fit.
     */
    public static function moduleDots(string $value, float $maxWidthInches): ?int
    {
        $modules = self::modules($value);

        foreach ([3, 2] as $dots) {
            if ($modules * $dots / self::PRINTER_DPI <= $maxWidthInches) {
                return $dots;
            }
        }

        return null;
    }

    /**
     * The barcode as an inline SVG of exact physical size.
     *
     * Width is the module count times the module size, so the drawing is never
     * stretched sideways; bars are vertical, so height alone may be chosen.
     */
    public static function svg(string $value, float $maxWidthInches, float $heightInches): string
    {
        $dots = self::moduleDots($value, $maxWidthInches) ?? 2;
        $modules = self::modules($value);
        $widthInches = $modules * $dots / self::PRINTER_DPI;

        $x = self::QUIET_ZONE;
        $path = '';

        foreach (self::widths($value) as $index => $width) {
            if ($index % 2 === 0) {
                $path .= "M{$x} 0h{$width}v1h-{$width}z";
            }

            $x += $width;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" width="%sin" height="%sin" viewBox="0 0 %d 1" preserveAspectRatio="none" shape-rendering="crispEdges"><rect width="%d" height="1" fill="#fff"/><path d="%s" fill="#000"/></svg>',
            self::inches($widthInches),
            self::inches($heightInches),
            $modules,
            $modules,
            $path,
        );
    }

    protected static function inches(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
