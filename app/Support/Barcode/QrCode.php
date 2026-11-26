<?php

namespace App\Support\Barcode;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use InvalidArgumentException;

/**
 * QR codes, drawn as SVG, for shipping labels.
 *
 * The symbol itself comes from BaconQrCode's encoder (already installed for
 * two-factor sign-in); only the drawing is ours, for the same reason as
 * {@see Code128}: every module is a whole number of 203 DPI printer dots, so
 * no module edge is resampled by the thermal head. Error correction is level M
 * (15%), which survives a scuffed label without growing the symbol much.
 *
 * Geometry only: the encoded value is never written into the SVG as text.
 */
class QrCode
{
    /** Clear space around the symbol, in modules (ISO/IEC 18004). */
    public const QUIET_ZONE = 4;

    /** Below this a 203 DPI head cannot draw a module reliably. */
    public const MIN_MODULE_DOTS = 3;

    /**
     * Whether a value can be encoded: printable ASCII, short enough for a
     * symbol a label can hold.
     */
    public static function canEncode(string $value): bool
    {
        return $value !== '' && strlen($value) <= 64 && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /**
     * The symbol's modules, dark as true, quiet zone excluded.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $value): array
    {
        if (! self::canEncode($value)) {
            throw new InvalidArgumentException('A label QR code encodes up to 64 printable ASCII characters.');
        }

        $matrix = Encoder::encode($value, ErrorCorrectionLevel::M(), 'ISO-8859-1')->getMatrix();
        $rows = [];

        for ($y = 0; $y < $matrix->getHeight(); $y++) {
            $row = [];

            for ($x = 0; $x < $matrix->getWidth(); $x++) {
                $row[] = $matrix->get($x, $y) === 1;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The widest whole-dot module whose symbol, quiet zone included, fits in
     * `$maxInches` square; never below {@see MIN_MODULE_DOTS}.
     */
    public static function moduleDots(string $value, float $maxInches): int
    {
        $modules = count(self::matrix($value)) + 2 * self::QUIET_ZONE;

        return max(self::MIN_MODULE_DOTS, (int) floor($maxInches * Code128::PRINTER_DPI / $modules));
    }

    /**
     * The symbol's side, quiet zone included, at `$dots` printer dots a module.
     */
    public static function inchesAt(string $value, int $dots): float
    {
        return (count(self::matrix($value)) + 2 * self::QUIET_ZONE) * $dots / Code128::PRINTER_DPI;
    }

    /**
     * The QR code as an inline SVG of exact physical size, quiet zone included.
     */
    public static function svg(string $value, float $maxInches): string
    {
        $rows = self::matrix($value);
        $modules = count($rows) + 2 * self::QUIET_ZONE;
        $inches = $modules * self::moduleDots($value, $maxInches) / Code128::PRINTER_DPI;

        $path = '';

        foreach ($rows as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= sprintf('M%d %dh1v1h-1z', $x + self::QUIET_ZONE, $y + self::QUIET_ZONE);
                }
            }
        }

        $size = rtrim(rtrim(sprintf('%.4F', $inches), '0'), '.');

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" width="%sin" height="%sin" viewBox="0 0 %d %d" shape-rendering="crispEdges"><rect width="%d" height="%d" fill="#fff"/><path d="%s" fill="#000"/></svg>',
            $size,
            $size,
            $modules,
            $modules,
            $modules,
            $modules,
            $path,
        );
    }
}
