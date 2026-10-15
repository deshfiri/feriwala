<?php

namespace App\Domain\Website;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Queries\ResolveWebsiteHost;

/**
 * Where a website's customer may be sent: its address under the storefront
 * domain, or a domain it holds that is verified and active (§16.2, D30, P2 H8).
 *
 * The one answer to "may a customer be sent here?". A return address after
 * paying is followed only when it passes {@see self::allows()} — when the order
 * is taken, and again at the moment of the redirect — so the payment flow can
 * never be turned into a redirect to somewhere else.
 *
 * **Which website a host belongs to is {@see ResolveWebsiteHost}'s answer**,
 * the same one the certificate check and the storefront's host binding use: a
 * domain that is unverified, suspended, lapsed or another website's is not
 * this website's, whatever `websites.domain` says.
 */
class WebsiteAddresses
{
    /** Longest return address accepted, in bytes. */
    public const MAX_URL_LENGTH = 2048;

    public function __construct(
        protected ResolveWebsiteHost $resolver,
    ) {}

    /**
     * The hosts this website's customers may be sent to now, its own domain
     * first.
     *
     * @return list<string>
     */
    public function hosts(Website $website): array
    {
        // A local address is its own host: there is no DNS to consult.
        if (($origin = LocalAddress::originOf($website)) !== null) {
            return [(string) parse_url($origin, PHP_URL_HOST).(parse_url($origin, PHP_URL_PORT) !== null ? ':'.parse_url($origin, PHP_URL_PORT) : '')];
        }

        $candidates = [
            $website->domain,
            $website->subdomain.'.'.config('website.storefront_domain'),
        ];

        $hosts = [];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && ($host = $this->ownHost($website, $candidate)) !== null) {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * The storefront's home page, or null when the website has no address a
     * customer may be sent to (a closed website).
     */
    public function home(Website $website): ?string
    {
        if (($origin = LocalAddress::originOf($website)) !== null) {
            return $origin.'/';
        }

        $host = $this->hosts($website)[0] ?? null;

        return $host === null ? null : 'https://'.$host.'/';
    }

    /**
     * Whether a customer may be sent to `$url` for this website (H8).
     *
     * HTTPS on the standard port, to a host this website holds now, with no
     * credentials, no fragment, nothing outside printable ASCII, and no longer
     * than {@see self::MAX_URL_LENGTH}. The host is compared after the same
     * canonicalisation DNS applies — case, a trailing dot, an internationalised
     * name — so two spellings of one name get one answer.
     */
    public function allows(Website $website, string $url): bool
    {
        // Whitespace, control characters, a backslash or raw non-ASCII are
        // where browsers and parsers disagree about the host.
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/^[\x21-\x7e]+$/', $url) !== 1 || str_contains($url, '\\')) {
            return false;
        }

        // A local website's own origin, over plain http, and nothing else.
        if (($origin = LocalAddress::originOf($website)) !== null
            && (str_starts_with($url, $origin.'/') || $url === $origin)
            && ! str_contains($url, '#')
            && ! str_contains(substr($url, strlen($origin)), '@')) {
            return true;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! str_starts_with($url, 'https://')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || str_contains($url, '#')
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! isset($parts['host'])) {
            return false;
        }

        return $this->ownHost($website, $parts['host']) !== null;
    }

    /**
     * `$host` in canonical form when it belongs to this website now.
     */
    protected function ownHost(Website $website, string $host): ?string
    {
        $resolved = $this->resolver->resolve($host);

        return $resolved !== null && $resolved->website->is($website) ? $resolved->host : null;
    }
}
