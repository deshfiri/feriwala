<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\WebsiteProduct;

/**
 * Tell a storefront that one of its products changed (contract §7.1, P5-22).
 *
 * **A hint, not the product** (contract §7.5). The payload says which product,
 * what happened, its selling price and when it changed — enough for the
 * storefront to decide to re-read it — and nothing a storefront could mistake
 * for the source of truth. Never a wholesale price, a cost or a margin (D12).
 *
 * The selection is marked **pending** until the delivery lands, so the
 * partner's product screen says the shop has not been told yet.
 */
class SyncWebsiteProduct
{
    public function __construct(
        protected PublishWebsiteEvent $publish,
    ) {}

    public function handle(WebsiteProduct $selection, WebhookEvent $event): ?WebhookDelivery
    {
        $selection->loadMissing(['website', 'product']);

        // Bookkeeping, not an edit: through the base query so `updated_at` —
        // what a storefront's `updated_since` reads — stays the content's.
        if ($selection->sync_status !== WebsiteSyncStatus::Pending) {
            WebsiteProduct::query()->whereKey($selection->id)->toBase()->update([
                'sync_status' => WebsiteSyncStatus::Pending->value,
            ]);
        }

        return $this->publish->handle(
            $selection->website,
            $event,
            [
                'id' => $selection->product->public_id,
                'sku' => $selection->product->sku,
                'slug' => $selection->product->slug,
                'status' => $selection->status->value,
                'price' => $selection->sellingPrice()?->jsonSerialize(),
                'updated_at' => $selection->updated_at->toIso8601String(),
            ],
            'website_product',
            $selection->id,
        );
    }
}
