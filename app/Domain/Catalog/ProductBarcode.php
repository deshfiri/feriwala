<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Actions\GenerateProductIdentifiers;

/**
 * EAN-13: the check digit, the bar pattern, and an SVG rendering of it.
 *
 * Built from the public GS1 encoding tables rather than a dependency, so a
 * barcode a product was never given one for can still be downloaded and
 * scanned. {@see GenerateProductIdentifiers}
 * generates the 13 digits; this turns any 13 digits into bars.
 */
class ProductBarcode
{
    /**
     * Left-hand digit encodings, 7 modules each: "L" (odd parity) and "G"
     * (even parity), chosen per digit by {@see self::PARITY}.
     *
     * @var array<int, string>
     */
    private const L_CODE = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /**
     * @var array<int, string>
     */
    private const G_CODE = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /**
     * Right-hand digit encodings, 7 modules each.
     *
     * @var array<int, string>
     */
    private const R_CODE = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    /**
     * Which of the left six digits take the G code, indexed by the leading
     * digit of the 13 — a '1' at a position means G, a '0' means L.
     *
     * @var array<int, string>
     */
    private const PARITY = [
        '000000', '001011', '001101', '001110', '010011',
        '011001', '011100', '010101', '010110', '011010',
    ];

    /**
     * The 13th digit for the 12 digits given, by the standard EAN-13 weighting
     * (alternating ×1 and ×3, reading left to right).
     */
    public static function checkDigit(string $digits12): int
    {
        $sum = 0;

        foreach (str_split($digits12) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * The 95-module wide bar pattern for a 13-digit code: start guard, six
     * digits, middle guard, six digits, end guard — as a string of '1' (ink)
     * and '0' (gap).
     */
    public static function modules(string $digits13): string
    {
        $digits = str_split($digits13);
        $parity = self::PARITY[(int) $digits[0]];

        $left = '';

        foreach (array_slice($digits, 1, 6) as $index => $digit) {
            $left .= $parity[$index] === '1' ? self::G_CODE[(int) $digit] : self::L_CODE[(int) $digit];
        }

        $right = '';

        foreach (array_slice($digits, 7, 6) as $digit) {
            $right .= self::R_CODE[(int) $digit];
        }

        return '101'.$left.'01010'.$right.'101';
    }

    /**
     * An SVG rendering, printed with the 13 digits underneath — the shape a
     * downloaded barcode arrives in, ready to go on a label.
     */
    public static function toSvg(string $digits13, int $moduleWidth = 2, int $barHeight = 80): string
    {
        $modules = self::modules($digits13);
        $width = strlen($modules) * $moduleWidth;
        $height = $barHeight + 24;

        $bars = '';
        $x = 0;

        foreach (str_split($modules) as $module) {
            if ($module === '1') {
                $bars .= sprintf(
                    '<rect x="%d" y="0" width="%d" height="%d" fill="#000"/>',
                    $x,
                    $moduleWidth,
                    $barHeight,
                );
            }

            $x += $moduleWidth;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">'
            .'<rect width="100%%" height="100%%" fill="#fff"/>'
            .'<g>%3$s</g>'
            .'<text x="%4$d" y="%5$d" font-family="monospace" font-size="16" letter-spacing="2" text-anchor="middle">%6$s</text>'
            .'</svg>',
            $width,
            $height,
            $bars,
            (int) ($width / 2),
            $barHeight + 20,
            $digits13,
        );
    }
}
