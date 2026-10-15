<?php

namespace App\Domain\Website;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Str;

/**
 * A website address typed as a URL, for trying a storefront on one's own machine.
 *
 * `http://127.0.0.1:8001`, `127.0.0.1:8001` and `localhost:8080` are all fine
 * here, and nothing about them is checked beyond being a host and a port. That
 * is a decision about a developer's laptop, so it is **off unless the
 * application runs as `local`** (config `website.allow_local_addresses`): in
 * production the setup form keeps asking for a proper subdomain, and a customer
 * is never sent to a plain-HTTP or private address.
 *
 * The host and port are kept in `websites.domain`; the website still gets an
 * ordinary generated subdomain label, which is what the rest of the platform
 * keys on.
 */
final class LocalAddress
{
    public static function enabled(): bool
    {
        return (bool) config('website.allow_local_addresses', false);
    }

    /**
     * The host (and port) of an address typed as a URL, or null when it is an
     * ordinary subdomain label — or when local addresses are not allowed.
     *
     * @return array{host: string, origin: string}|null
     */
    public static function parse(string $input): ?array
    {
        if (! self::enabled()) {
            return null;
        }

        $value = Str::lower(trim($input));

        if ($value === '') {
            return null;
        }

        $looksLikeAnAddress = str_contains($value, '://')
            || str_contains($value, ':')
            || str_contains($value, '/')
            || $value === 'localhost'
            || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $value) === 1;

        if (! $looksLikeAnAddress) {
            return null;
        }

        $parts = parse_url(str_contains($value, '://') ? $value : 'http://'.$value);

        if (! is_array($parts) || ! isset($parts['host']) || preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $parts['host']) !== 1) {
            return null;
        }

        // parse_url() has already refused a port outside 1–65535.
        $port = $parts['port'] ?? null;

        $host = $parts['host'].($port !== null ? ':'.$port : '');

        return ['host' => $host, 'origin' => 'http://'.$host];
    }

    /**
     * A subdomain label for an address, unique among websites.
     */
    public static function label(string $host): string
    {
        $base = 'local-'.trim((string) preg_replace('/[^a-z0-9]+/', '-', $host), '-');
        $base = substr($base, 0, 54);
        $label = $base;

        while (Website::query()->where('subdomain', $label)->exists()) {
            $label = $base.'-'.Str::lower(Str::random(4));
        }

        return $label;
    }

    /**
     * Where this website's customers are sent, when it has a local address.
     */
    public static function originOf(Website $website): ?string
    {
        if (! self::enabled() || $website->domain === null) {
            return null;
        }

        $parsed = self::parse($website->domain);

        // A bare domain name is a real domain, not a local address: only a
        // host with a port, `localhost` or an IP is treated as local.
        $isLocal = $parsed !== null
            && (str_contains($website->domain, ':')
                || $website->domain === 'localhost'
                || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $website->domain) === 1);

        return $isLocal ? $parsed['origin'] : null;
    }
}
