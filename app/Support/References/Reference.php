<?php

namespace App\Support\References;

use App\Concerns\HasReference;
use Random\RandomException;

/**
 * Generates human-readable business references such as `ORD-260901-K7M3QX9P`.
 *
 * The alphabet deliberately omits I, L, O, U, 0 and 1 so a reference read aloud
 * or copied by hand cannot be mistaken between similar-looking characters.
 * Uniqueness is guaranteed by a unique index on the column, not by this class —
 * see {@see HasReference} for the retry behaviour.
 */
class Reference
{
    /**
     * Unambiguous Crockford-style alphabet.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const RANDOM_LENGTH = 8;

    /**
     * Build a reference for the given prefix.
     */
    public static function generate(ReferencePrefix $prefix, ?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable;

        return sprintf(
            '%s-%s-%s',
            $prefix->value,
            $date->format('ymd'),
            self::randomSegment(),
        );
    }

    /**
     * Whether a string looks like a reference carrying the given prefix.
     */
    public static function matches(string $reference, ReferencePrefix $prefix): bool
    {
        $pattern = sprintf(
            '/^%s-\d{6}-[%s]{%d}$/',
            preg_quote($prefix->value, '/'),
            preg_quote(self::ALPHABET, '/'),
            self::RANDOM_LENGTH,
        );

        return (bool) preg_match($pattern, $reference);
    }

    /**
     * @throws RandomException
     */
    protected static function randomSegment(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $segment = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $segment .= $alphabet[random_int(0, $max)];
        }

        return $segment;
    }
}
