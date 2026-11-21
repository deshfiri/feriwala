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

    /** Hosts whose explicitly typed port is always kept: a developer's own machine. */
    public const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '::1'];

    /**
     * Whatever was typed or pasted, taken apart: the scheme if one was given,
     * the host in canonical form, and the port if one was given. Null when it
     * is malformed -- no host, a character no host has, or a port that is not
     * a whole number from 1 to 65535.
     *
     * Forgiving about everything else, so `Shop.com`, `shop.com `,
     * `https://www.shop.com/about?x=1`, `shop.com.` and `user@shop.com` all
     * mean what the person meant: credentials, path, query, fragment, trailing
     * dots and stray whitespace (including non-breaking spaces from a
     * copy-paste) are dropped, and an internationalised name such as a Bangla
     * domain becomes its ASCII (punycode) form, which DNS and certificates use.
     *
     * @return array{scheme: string|null, host: string, port: int|null}|null
     */
    public static function parts(string $input): ?array
    {
        // Unicode whitespace too: a pasted address often carries a NBSP or a
        // zero-width space that trim() does not see.
        $value = (string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u', '', $input);
        $value = Str::lower($value);
        $scheme = null;

        if (preg_match('#^([a-z][a-z0-9+.\-]*)://#', $value, $match) === 1) {
            $scheme = $match[1];
            $value = substr($value, strlen($match[0]));
        }

        $value = (string) preg_replace('#[/?\#\\\\].*$#', '', $value);

        if (str_contains($value, '@')) {
            $value = substr($value, strrpos($value, '@') + 1);
        }

        if (preg_match('/^\[([0-9a-f:.]+)\](?::(.*))?$/', $value, $match) === 1) {
            // An IPv6 address, which can carry a port only in brackets.
            $host = $match[1];
            $portText = $match[2] ?? '';

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } elseif (substr_count($value, ':') > 1) {
            $host = $value;
            $portText = '';

            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
        } else {
            [$host, $portText] = array_pad(explode(':', $value, 2), 2, '');
            $host = trim($host, '.');

            if ($host !== '' && preg_match('/[^\x00-\x7f]/', $host) === 1 && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                $host = is_string($ascii) ? $ascii : $host;
            }

            if ($host === '' || preg_match('/^[a-z0-9_.\-]+$/', $host) !== 1) {
                return null;
            }
        }

        $port = null;

        if ($portText !== '') {
            if (preg_match('/^\d{1,5}$/', $portText) !== 1 || (int) $portText < 1 || (int) $portText > 65535) {
                return null;
            }

            $port = (int) $portText;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /**
     * The address in whatever was typed or pasted, in one canonical form: the
     * host, plus the port only where it means something -- one typed for a
     * loopback host ({@see self::LOOPBACK_HOSTS}), or the port of a configured
     * development or staging origin (`website.port_origins`). Every other port,
     * and a default one (80 for http, 443 for https), is dropped, so a
     * production address is the host alone, exactly as before. Empty when what
     * was typed is malformed.
     *
     * The one cleaner every address field uses.
     */
    public static function normalize(string $input): string
    {
        $parts = self::parts($input);

        if ($parts === null) {
            return '';
        }

        return self::keepsPort($parts) ? self::hostForUrl($parts['host']).':'.$parts['port'] : $parts['host'];
    }

    /**
     * The full origin -- scheme, host and any port worth keeping -- or null
     * when what was typed is malformed. Without a scheme, a loopback host is
     * taken as http, a configured origin keeps its own scheme, and anything
     * else is https.
     */
    public static function origin(string $input): ?string
    {
        $parts = self::parts($input);

        if ($parts === null) {
            return null;
        }

        $scheme = $parts['scheme']
            ?? self::configuredOrigin($parts)['scheme']
            ?? (in_array($parts['host'], self::LOOPBACK_HOSTS, true) ? 'http' : 'https');

        return $scheme.'://'.self::hostForUrl($parts['host']).(self::keepsPort($parts) ? ':'.$parts['port'] : '');
    }

    /**
     * The canonical host name, or null when what was typed is not a public
     * domain at all (no dot, an illegal character, too long, or an IP address
     * -- a machine, not a website's name; a developer's local address is
     * {@see LocalAddress}'s to accept, and only where that is allowed).
     */
    public static function host(string $input): ?string
    {
        $value = self::parts($input)['host'] ?? '';

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return strlen($value) <= 253 && preg_match(self::DOMAIN_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * Whether a port is the scheme's own (80 for http, 443 for https; either,
     * when no scheme was given).
     */
    public static function isDefaultPort(?string $scheme, int $port): bool
    {
        return match ($scheme) {
            'http' => $port === 80,
            'https' => $port === 443,
            default => in_array($port, [80, 443], true),
        };
    }

    /**
     * @param  array{scheme: string|null, host: string, port: int|null}  $parts
     */
    private static function keepsPort(array $parts): bool
    {
        if ($parts['port'] === null || self::isDefaultPort($parts['scheme'], $parts['port'])) {
            return false;
        }

        return in_array($parts['host'], self::LOOPBACK_HOSTS, true) || self::configuredOrigin($parts) !== null;
    }

    /**
     * The configured development or staging origin with this host and port,
     * if any (`website.port_origins`; empty unless set, so production keeps
     * no port).
     *
     * @param  array{scheme: string|null, host: string, port: int|null}  $parts
     * @return array{scheme: string|null, host: string, port: int|null}|null
     */
    private static function configuredOrigin(array $parts): ?array
    {
        if ($parts['port'] === null) {
            return null;
        }

        // Outside a booted application (a plain unit test) nothing is configured.
        $origins = app()->bound('config') ? (array) config('website.port_origins', []) : [];

        foreach ($origins as $origin) {
            $configured = is_string($origin) ? self::parts($origin) : null;

            if ($configured !== null
                && $configured['port'] !== null
                && $configured['host'] === $parts['host']
                && $configured['port'] === $parts['port']
                && ($parts['scheme'] === null || $parts['scheme'] === $configured['scheme'])) {
                return $configured;
            }
        }

        return null;
    }

    private static function hostForUrl(string $host): string
    {
        return str_contains($host, ':') ? '['.$host.']' : $host;
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
