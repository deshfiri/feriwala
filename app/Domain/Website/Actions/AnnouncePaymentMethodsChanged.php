<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tell every storefront whose checkout just changed (P2 D2).
 *
 * The payment methods `GET /site` reports are platform-wide: online payment is
 * offered in a currency while any gateway can take it. When a gateway change
 * makes that true or false for a currency, every website selling in it now
 * offers something different, and each one served with a webhook endpoint is
 * sent `website.updated` through {@see AnnounceWebsiteUpdated} — which keeps one
 * pending delivery per website, so a burst of changes is one event each.
 *
 * Websites in other currencies, not served, or with no endpoint are not
 * touched. Paged, so the number of websites never decides how long one pass
 * holds anything; run from a queued job, never from the admin's request.
 *
 * Cash-on-delivery terms have no writer yet. Whatever comes to change them
 * platform-wide must announce through here too, and a per-website change
 * through {@see AnnounceWebsiteUpdated} directly.
 */
class AnnouncePaymentMethodsChanged
{
    public function __construct(
        protected AnnounceWebsiteUpdated $announce,
    ) {}

    /**
     * @param  list<string>  $currencies  whose online availability changed
     * @return int how many websites were told
     */
    public function handle(array $currencies): int
    {
        if ($currencies === []) {
            return 0;
        }

        $served = array_values(array_map(
            fn (WebsiteStatus $status) => $status->value,
            array_filter(WebsiteStatus::cases(), fn (WebsiteStatus $status) => $status->acceptsSync()),
        ));

        $told = 0;

        Website::query()
            ->whereIn('currency_code', $currencies)
            ->whereIn('status', $served)
            ->whereIn('id', WebsiteWebhookEndpoint::query()->where('is_active', true)->select('website_id'))
            ->chunkById(200, function (Collection $websites) use (&$told) {
                foreach ($websites as $website) {
                    $told += $this->announce->handle($website) === null ? 0 : 1;
                }
            });

        return $told;
    }
}
