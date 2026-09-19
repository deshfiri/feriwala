<?php

namespace App\Support\Localization;

/**
 * Mobile numbers as the one key a storefront customer is found by
 * (contract §4.3.1, §6.2).
 *
 * Normalised to E.164 on receipt: spaces, hyphens, dots and parentheses go, a
 * leading `00` becomes `+`, and a national number's leading `0` becomes the
 * country's dialling code. `01712-345678` and `+8801712345678` are one customer.
 *
 * What cannot be resolved is refused rather than guessed — a number stored in a
 * shape nobody else's lookup will match is a second customer nobody asked for.
 * Bangladeshi numbers are held to the operator ranges; elsewhere, to E.164's
 * length.
 */
class MobileNumber
{
    /** Countries whose national numbers can be resolved, by dialling code. */
    public const DIALLING_CODES = [
        'BD' => '880',
    ];

    /** A Bangladeshi mobile: 01, an operator digit 3–9, then eight digits. */
    protected const BANGLADESH = '/^\+8801[3-9][0-9]{8}$/';

    protected const E164 = '/^\+[1-9][0-9]{7,14}$/';

    /**
     * The E.164 form, or null when the input cannot be resolved.
     */
    public function normalise(string $input, string $country = 'BD'): ?string
    {
        $digits = preg_replace('/[\s\-.()]/u', '', trim($input)) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        $dialling = self::DIALLING_CODES[strtoupper($country)] ?? null;

        if (! str_starts_with($digits, '+')) {
            if ($dialling !== null && str_starts_with($digits, $dialling)) {
                // The dialling code without its plus: 8801712345678.
                $digits = '+'.$digits;
            } elseif ($dialling !== null && str_starts_with($digits, '0')) {
                $digits = '+'.$dialling.substr($digits, 1);
            } else {
                return null;
            }
        }

        if (preg_match(self::E164, $digits) !== 1) {
            return null;
        }

        if (str_starts_with($digits, '+880') && preg_match(self::BANGLADESH, $digits) !== 1) {
            return null;
        }

        return $digits;
    }
}
