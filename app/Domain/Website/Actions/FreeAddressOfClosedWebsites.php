<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsiteServiceStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;

/**
 * Let a domain go from websites that are closed.
 *
 * A closed website answers nothing, so its hold on a name only stops the next
 * website from using it. {@see MoveWebsiteStatus} lets go when a website is
 * closed; this covers the ones closed before it did, at the moment somebody
 * wants the name. A website that is still open keeps its domain.
 */
class FreeAddressOfClosedWebsites
{
    public function handle(string $domain): void
    {
        $closed = Website::query()
            ->where('status', WebsiteStatus::Closed->value)
            ->where(fn ($query) => $query
                ->where('domain', $domain)
                ->orWhereHas('domains', fn ($domains) => $domains->where('domain', $domain)))
            ->get();

        foreach ($closed as $website) {
            WebsiteDomain::query()
                ->where('website_id', $website->id)
                ->where('domain', $domain)
                ->update(['status' => WebsiteServiceStatus::Cancelled->value, 'routing_status' => 'unverified']);

            if ($website->domain === $domain) {
                $website->forceFill([
                    'requested_address' => $website->requested_address ?? $domain,
                    'domain' => null,
                ])->save();
            }
        }
    }
}
