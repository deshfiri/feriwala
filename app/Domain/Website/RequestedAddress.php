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
     * The typed address, lowercased and trimmed, with a scheme, path, query and
     * fragment taken off a URL.
     */
    public static function normalize(string $input): string
    {
        $value = Str::lower(trim($input));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $value);
        $value = (string) preg_replace('#[/?\#].*$#', '', $value);

        return trim($value);
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
