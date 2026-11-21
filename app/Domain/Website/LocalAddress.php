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
     * ordinary subdomain label, when it is malformed (a bad port, no host) --
     * or when local addresses are not allowed.
     *
     * A typed port is kept unless it is the scheme's default (80 for http, 443
     * for https). `host` is host and port, for naming; `stored` is what goes in
     * `websites.domain` -- the same, with `https://` in front when the address
     * was typed as https, so {@see self::originOf()} rebuilds the full origin.
     *
     * @return array{host: string, stored: string, origin: string}|null
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

        $parts = RequestedAddress::parts($value);

        if ($parts === null || preg_match('/^([a-z0-9]([a-z0-9.\-]*[a-z0-9])?|[0-9a-f:.]+)$/', $parts['host']) !== 1) {
            return null;
        }

        $scheme = $parts['scheme'] === 'https' ? 'https' : 'http';
        $host = str_contains($parts['host'], ':') ? '['.$parts['host'].']' : $parts['host'];

        if ($parts['port'] !== null && ! RequestedAddress::isDefaultPort($scheme, $parts['port'])) {
            $host .= ':'.$parts['port'];
        }

        $origin = $scheme.'://'.$host;

        return ['host' => $host, 'stored' => $scheme === 'https' ? $origin : $host, 'origin' => $origin];
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
                || in_array($website->domain, RequestedAddress::LOOPBACK_HOSTS, true)
                || preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $website->domain) === 1);

        return $isLocal ? $parsed['origin'] : null;
    }
}
