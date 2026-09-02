<?php

namespace App\Domain\Referral;

use App\Models\User;
use RuntimeException;

/**
 * Generates the unique referral code every active user receives (§25.1).
 *
 * The code is typed by hand and read aloud far more often than it is clicked, so
 * it uses the same unambiguous alphabet as business references — no I, L, O, U,
 * 0 or 1 — and a fixed length that is easy to check.
 *
 * Codes are random rather than sequential. A sequential code would let anyone
 * enumerate the user base and estimate how fast Feriwala is growing.
 */
class ReferralCode
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 8;

    protected const ATTEMPTS = 6;

    /**
     * Produce a code no other account is using.
     */
    public function generate(): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $code = $this->random();

            if (! User::query()->where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException(
            'Could not generate a unique referral code after '.self::ATTEMPTS.' attempts.'
        );
    }

    /**
     * Whether a string is shaped like a referral code.
     *
     * Checked before hitting the database so an obviously invalid code is
     * rejected without a query, and so a lookup cannot be used to probe.
     */
    public function isWellFormed(string $code): bool
    {
        return (bool) preg_match(
            '/^['.preg_quote(self::ALPHABET, '/').']{'.self::LENGTH.'}$/',
            $code,
        );
    }

    /**
     * Normalise user input — codes get typed in lower case and with spaces.
     */
    public function normalise(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    protected function random(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }
}
