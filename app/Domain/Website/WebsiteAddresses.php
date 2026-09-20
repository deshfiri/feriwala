<?php

namespace App\Domain\Website;

use App\Domain\Website\Models\Website;

/**
 * Where a website's storefront lives: its own domain, or its address under the
 * storefront domain (§16.2).
 *
 * The one answer to "may a customer be sent here?" — a return address after
 * paying is followed only to one of these, over https, so the payment flow can
 * never be turned into a redirect to somewhere else.
 */
class WebsiteAddresses
{
    /**
     * @return array<int, string>
     */
    public function hosts(Website $website): array
    {
        return array_values(array_filter([
            $website->domain !== null ? mb_strtolower($website->domain) : null,
            mb_strtolower($website->subdomain.'.'.config('website.storefront_domain')),
        ]));
    }

    public function home(Website $website): string
    {
        return 'https://'.$this->hosts($website)[0].'/';
    }

    public function allows(Website $website, string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array(mb_strtolower((string) ($parts['host'] ?? '')), $this->hosts($website), true);
    }
}
