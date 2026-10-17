<?php

namespace App\Domain\Website;

/**
 * Where a storefront's banner goes when it is clicked.
 *
 * The banner image is the link itself, so what is stored must be safe to put in
 * an `href` on somebody else's shop. Two shapes are allowed and nothing else:
 *
 * - a path on the shop itself (`/products`, `/category/saris`), opened by normal
 *   same-site navigation; never protocol-relative (`//host`), which would leave
 *   the shop while looking like it stayed;
 * - an absolute `https://` address, opened in a new tab without handing the
 *   destination the shop (`rel="noopener noreferrer"`). Plain `http://` is
 *   accepted only where the platform already accepts local addresses, for a
 *   developer machine.
 *
 * `javascript:`, `data:`, `vbscript:`, embedded credentials, control characters
 * and anything longer than a browser will carry are refused, not repaired.
 */
class BannerLink
{
    public const MAX_LENGTH = 2048;

    /**
     * The address to store, or null when there is none.
     *
     * @return string|null null for an empty value
     *
     * @throws \InvalidArgumentException when the value is not an acceptable address
     */
    public static function normalise(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return null;
        }

        if (! self::isAcceptable($value)) {
            throw new \InvalidArgumentException('That is not an address a banner may link to.');
        }

        return $value;
    }

    public static function isAcceptable(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1) {
            return false;
        }

        if (str_starts_with($value, '/')) {
            return ! str_starts_with($value, '//');
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        return $scheme === 'https' || ($scheme === 'http' && (bool) config('website.allow_local_addresses'));
    }

    /**
     * Whether the address leaves the shop, so the storefront knows to open it
     * safely in a new tab.
     */
    public static function isExternal(?string $value): bool
    {
        return $value !== null && ! str_starts_with($value, '/');
    }
}
