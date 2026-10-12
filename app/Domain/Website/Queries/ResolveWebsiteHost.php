<?php

namespace App\Domain\Website\Queries;

use App\Domain\Website\Data\ResolvedWebsiteHost;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;

/**
 * Which website a host name belongs to, if any (D30 §3).
 *
 * The single answer the certificate check, the storefront's stated host and,
 * later, the storefront's credential registry all rely on, so it is strict:
 *
 *   - `{subdomain}.{storefront domain}` belongs to the website holding that
 *     subdomain, unless the website is closed.
 *   - Any other name belongs to a website only through a domain that is
 *     **verified and active**, whose registration is running, on a website
 *     that is not closed.
 *   - Everything else — unknown, unverified, suspended, lapsed, malformed — is
 *     nobody's.
 *
 * A suspended *website* still resolves, so it keeps HTTPS for its
 * "unavailable" page; it takes no orders, because the Storefront API refuses
 * its credentials and order submission needs a live website (D30 §4, D6).
 *
 * Never reads a website from anything but the name itself.
 */
class ResolveWebsiteHost
{
    /** Longest legal DNS name, without a trailing dot. */
    protected const MAX_LENGTH = 253;

    public function resolve(string $host): ?ResolvedWebsiteHost
    {
        $name = $this->normalize($host);

        if ($name === null) {
            return null;
        }

        $suffix = '.'.mb_strtolower((string) config('website.storefront_domain'));

        if (str_ends_with($name, $suffix)) {
            $label = substr($name, 0, -strlen($suffix));

            // One label only: `a.b.feriwala.shop` is nobody's subdomain.
            if ($label === '' || str_contains($label, '.')) {
                return null;
            }

            /** @var Website|null $website */
            $website = Website::query()->where('subdomain', $label)->open()->first();

            return $website === null ? null : new ResolvedWebsiteHost($name, $website, 'subdomain');
        }

        /** @var WebsiteDomain|null $domain */
        $domain = WebsiteDomain::query()
            ->with('website')
            ->where('domain', $name)
            ->where('routing_status', DomainRoutingStatus::Active->value)
            ->where('status', WebsiteServiceStatus::Active->value)
            ->whereHas('website', fn ($query) => $query->where('status', '!=', WebsiteStatus::Closed->value))
            ->first();

        if ($domain === null) {
            return null;
        }

        return new ResolvedWebsiteHost(
            $name,
            $domain->website,
            $domain->website->domain === $name ? 'primary' : 'alias',
            $domain,
        );
    }

    /**
     * A host as DNS compares it: lower case, no port, no trailing dot, an
     * internationalised name in its ASCII form. Null for anything that is not
     * a plausible host name — an address, a path, a credential, a wildcard.
     */
    public function normalize(string $host): ?string
    {
        $name = mb_strtolower(trim($host));

        // A port belongs to the connection, not the name.
        if (preg_match('/^(.+?):\d{1,5}$/', $name, $matches) === 1) {
            $name = $matches[1];
        }

        $name = rtrim($name, '.');

        if ($name !== '' && preg_match('/[^\x20-\x7e]/', $name) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $name = $ascii === false ? '' : $ascii;
        }

        if ($name === '' || strlen($name) > self::MAX_LENGTH) {
            return null;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (preg_match('/^'.$label.'(?:\.'.$label.')+$/', $name) !== 1) {
            return null;
        }

        // A dotted quad is an address, never a website's name.
        if (preg_match('/^[\d.]+$/', $name) === 1) {
            return null;
        }

        return $name;
    }
}
