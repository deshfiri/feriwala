<?php

namespace App\Domain\Website\Data;

use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;

/**
 * The one website a host belongs to, and how (D30 §3).
 *
 * `kind` is `subdomain` for `{subdomain}.{storefront domain}`, `primary` for the
 * website's own address, and `alias` for any other active domain it holds.
 */
final readonly class ResolvedWebsiteHost
{
    /**
     * @param  'subdomain'|'primary'|'alias'  $kind
     */
    public function __construct(
        public string $host,
        public Website $website,
        public string $kind,
        public ?WebsiteDomain $domain = null,
    ) {}
}
