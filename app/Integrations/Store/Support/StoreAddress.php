<?php

namespace App\Integrations\Store\Support;

/**
 * A store's address, reduced to the one form everything compares (D29).
 *
 * Partners paste what their browser shows — with a trailing slash, the API
 * path, a docs page. The base address is the scheme, host and port; the
 * comparable host is that host in lower case, with the port only when it is
 * not the default. Two connections to the same host are the same store.
 */
final readonly class StoreAddress
{
    private function __construct(
        public string $baseUrl,
        public string $host,
        public string $normalizedHost,
    ) {}

    /**
     * Null when the address is not an HTTPS address with a host — plain HTTP
     * is refused outright, because the credentials travel with every call.
     */
    public static function parse(string $input): ?self
    {
        $parts = parse_url(trim($input));

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower(rtrim($parts['host'] ?? '', '.'));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $authority = $port !== null && $port !== 443 ? "{$host}:{$port}" : $host;

        return new self("https://{$authority}", $host, $authority);
    }
}
