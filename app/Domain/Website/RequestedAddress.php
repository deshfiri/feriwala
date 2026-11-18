<?php

namespace App\Domain\Website;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Str;

/**
 * The address a partner asks for on the website request form.
 *
 * A partner may ask for anything — `myshop`, `myshop.com`, `https://www.myshop.com/`
 * — so the form never refuses on shape. A plain subdomain label is the one thing
 * Feriwala hosts itself and is checked as before. Anything else is kept as
 * typed in `websites.requested_address` for staff, who register and verify the
 * domain (D9), and the website gets an ordinary generated subdomain label, which
 * is what the rest of the platform keys on. Asking never claims a domain.
 */
final class RequestedAddress
{
    public const LABEL_PATTERN = '/^[a-z0-9]([a-z0-9-]{1,61})?[a-z0-9]$/';

    /**
     * A domain name: two or more dot-separated labels of letters, digits,
     * hyphens and underscores (which real hosts do use), not starting or
     * ending a label with a hyphen. Applied after {@see self::normalize()}.
     */
    public const DOMAIN_PATTERN = '/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)+$/';

    /**
     * The host name in whatever was typed or pasted, in one canonical form.
     *
     * The one cleaner every address field uses, so `Shop.com`, `shop.com `,
     * `https://www.shop.com/about?x=1`, `shop.com:443`, `shop.com.` and
     * `user@shop.com` all mean what the person meant. Scheme, credentials,
     * port, path, query, fragment, trailing dots and stray whitespace (including
     * non-breaking spaces from a copy-paste) are dropped; an internationalised
     * name such as a Bangla domain becomes its ASCII (punycode) form, which is
     * what DNS and certificates use.
     */
    public static function normalize(string $input): string
    {
        // Unicode whitespace too: a pasted address often carries a NBSP or a
        // zero-width space that trim() does not see.
        $value = (string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $input);
        $value = Str::lower($value);
        $value = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $value);
        $value = (string) preg_replace('#[/?\#\\\\].*$#', '', $value);

        // Credentials before the host, and a port after it.
        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }

        $value = (string) preg_replace('/:\d*$/', '', $value);
        $value = trim($value, '.');

        if ($value !== '' && preg_match('/[^\x00-\x7f]/', $value) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            $value = is_string($ascii) ? $ascii : $value;
        }

        return $value;
    }

    /**
     * The canonical host name, or null when what was typed is not a public
     * domain at all (no dot, an illegal character, too long, or an IP address
     * -- a machine, not a website's name; a developer's local address is
     * {@see LocalAddress}'s to accept, and only where that is allowed).
     */
    public static function host(string $input): ?string
    {
        $value = self::normalize($input);

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return strlen($value) <= 253 && preg_match(self::DOMAIN_PATTERN, $value) === 1 ? $value : null;
    }

    public static function isPlainLabel(string $normalized): bool
    {
        return preg_match(self::LABEL_PATTERN, $normalized) === 1;
    }

    /**
     * A subdomain label for a free-form address, unique among websites.
     */
    public static function label(string $address): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', Str::ascii($address)), '-');
        $base = substr($base, 0, 54);
        $base = strlen($base) >= 3 ? $base : 'shop-'.Str::lower(Str::random(4));
        $label = $base;

        while (Website::query()->where('subdomain', $label)->exists()
            || in_array($label, (array) config('website.reserved_subdomains', []), true)) {
            $label = $base.'-'.Str::lower(Str::random(4));
        }

        return $label;
    }
}
