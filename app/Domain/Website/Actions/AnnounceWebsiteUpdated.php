<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use Carbon\CarbonImmutable;

/**
 * Tell a storefront that how its website is presented changed (P2 D2).
 *
 * Branding, contact details, images, and the hosts it is served on. **The
 * payload is deliberately minimal** — the website and the moment — because a
 * webhook is a hint, never the source of truth (contract §7.5): the storefront
 * reads `GET /site` for what changed. Nothing about the account, its wallet,
 * its package or its domains' registration ever rides on it.
 *
 * One pending delivery per website: a burst of edits before the first is sent
 * becomes one event carrying the latest moment, not a storm.
 */
class AnnounceWebsiteUpdated
{
    public function __construct(
        protected PublishWebsiteEvent $publish,
    ) {}

    public function handle(Website $website): ?WebhookDelivery
    {
        return $this->publish->handle(
            $website,
            WebhookEvent::WebsiteUpdated,
            [
                'id' => $website->public_id,
                'updated_at' => CarbonImmutable::now()->toIso8601String(),
            ],
            'website',
            $website->id,
        );
    }
}
