<?php

namespace App\Support\Localization;

use Illuminate\Contracts\Config\Repository;

/**
 * A mobile number from any country, chosen as a country plus the national
 * number (D39), and kept in E.164.
 *
 * The country picks the calling code (`config/dialling_codes.php`); the number
 * may be typed nationally (`01712 345678`), with its code (`8801712345678`) or
 * in full (`+8801712345678`). A single national trunk `0` is dropped, as almost
 * every country writes it but none dials it from abroad. A Bangladeshi number
 * is still held to the operator ranges; elsewhere, to E.164's length. What
 * cannot be resolved is refused rather than guessed.
 */
class InternationalMobile
{
    protected const BANGLADESH = '/^\+8801[3-9][0-9]{8}$/';

    protected const E164 = '/^\+[1-9][0-9]{6,14}$/';

    public function __construct(
        protected Repository $config,
    ) {}

    /**
     * @return array<string, array{0: string, 1: string}> ISO code => [calling code, name]
     */
    public function codes(): array
    {
        /** @var array<string, array{0: string, 1: string}> $codes */
        $codes = $this->config->get('dialling_codes', []);

        return $codes;
    }

    public function knows(?string $country): bool
    {
        return $country !== null && array_key_exists(strtoupper($country), $this->codes());
    }

    /**
     * Picker options, Bangladesh first and the rest by name.
     *
     * @return array<int, array{value: string, label: string, code: string}>
     */
    public function options(): array
    {
        $codes = $this->codes();
        $first = $codes['BD'] ?? null;
        unset($codes['BD']);
        uasort($codes, fn (array $a, array $b) => strcmp($a[1], $b[1]));

        $ordered = $first === null ? $codes : ['BD' => $first] + $codes;

        return array_map(
            fn (string $iso, array $entry) => ['value' => $iso, 'label' => $entry[1].' (+'.$entry[0].')', 'code' => $entry[0]],
            array_keys($ordered),
            array_values($ordered),
        );
    }

    /**
     * The E.164 number, or null when it cannot be resolved for that country.
     */
    public function normalise(string $country, string $number): ?string
    {
        $entry = $this->codes()[strtoupper($country)] ?? null;
        $digits = preg_replace('/[\s\-.()]/u', '', trim($number)) ?? '';

        if ($entry === null || $digits === '') {
            return null;
        }

        [$code] = $entry;

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }

        if (! str_starts_with($digits, '+')) {
            if (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 6) {
                $digits = '+'.$digits;
            } else {
                $digits = '+'.$code.(str_starts_with($digits, '0') ? substr($digits, 1) : $digits);
            }
        }

        if (preg_match(self::E164, $digits) !== 1 || ! str_starts_with($digits, '+'.$code)) {
            return null;
        }

        if (strtoupper($country) === 'BD' && preg_match(self::BANGLADESH, $digits) !== 1) {
            return null;
        }

        return $digits;
    }

    /**
     * The country a stored E.164 number most likely belongs to, for filling a
     * picker back in: the longest calling code that prefixes it, preferring the
     * given country when it shares that code.
     *
     * @return array{country: string, national: string}|null
     */
    public function split(?string $e164, string $preferred = 'BD'): ?array
    {
        if ($e164 === null || ! str_starts_with($e164, '+')) {
            return null;
        }

        $best = null;

        foreach ($this->codes() as $iso => [$code]) {
            if (! str_starts_with($e164, '+'.$code)) {
                continue;
            }

            $longer = $best === null || strlen($code) > strlen($best[1]);
            $sameButPreferred = $best !== null && strlen($code) === strlen($best[1]) && $iso === strtoupper($preferred);

            if ($longer || $sameButPreferred) {
                $best = [$iso, $code];
            }
        }

        return $best === null ? null : ['country' => $best[0], 'national' => substr($e164, strlen($best[1]) + 1)];
    }
}
