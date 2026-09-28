<?php

namespace App\Domain\Bank;

/**
 * The one, explicit, reviewed mapping from a bank branch's own district text
 * to `bd_locations.name_en`, for the three districts confirmed to differ
 * between the two independently sourced directories (see
 * database/data/bangladesh-bank/NOTICE.md, "Known, reviewed name
 * differences").
 *
 * Every other district name in the bundled bank data already matches a
 * `bd_locations` district after {@see normalize()} alone — this map exists
 * only for the handful that a later official rename left behind, and is
 * never extended by guessing; a name absent from both this map and a direct
 * normalized match is reported unmatched, not assigned to the
 * closest-looking district.
 */
final class DistrictAliases
{
    /**
     * Keyed and valued by {@see normalize()} output.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'BARISHAL' => 'BARISAL',
        'CUMILLA' => 'COMILLA',
        'JHALOKATI' => 'JHALAKATHI',
    ];

    /**
     * Uppercase, letters only — collapses case and the underscores the bank
     * data uses in place of spaces/apostrophes (`COXS_BAZAR`,
     * `CHAPAI_NAWABGANJ`) without discarding anything that actually
     * distinguishes two different names.
     */
    public static function normalize(string $districtName): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $districtName));
    }

    /**
     * The `bd_locations.name_en` value (normalized) a bank branch's own
     * district text resolves to, whether through the reviewed alias map or a
     * direct normalized match.
     */
    public static function resolve(string $districtName): string
    {
        $normalized = self::normalize($districtName);

        return self::MAP[$normalized] ?? $normalized;
    }
}
