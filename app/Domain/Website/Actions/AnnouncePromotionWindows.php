<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\WebsiteProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tell storefronts when a flash deal opens or closes (C4).
 *
 * A promotion window changes what a customer is charged without anybody saving
 * anything, so the clock has to announce it. Every minute this finds the
 * selections whose promotion is in force now but was last announced as not —
 * or the reverse — and for each one, under a row lock, records the new state,
 * moves `updated_at` (so `updated_since` reconciliation sees it too) and
 * publishes `product.price_changed` for a published product.
 *
 * **Once per opening and once per closing.** A row is announced only while its
 * recorded state differs from the clock's, and the announcement and the new
 * state commit together, so a second run, an overlapping run or a retry finds
 * nothing to do. Selections with no window are never candidates: nothing about
 * them changes with time.
 */
class AnnouncePromotionWindows
{
    public function __construct(
        protected SyncWebsiteProduct $sync,
    ) {}

    /**
     * @return int how many selections were announced
     */
    public function handle(?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        [$applies, $bindings] = WebsiteProduct::promotionAppliesSql($at);
        $announced = 0;

        WebsiteProduct::query()
            ->where(fn ($query) => $query
                ->whereNotNull('website_products.promotion_starts_at')
                ->orWhereNotNull('website_products.promotion_ends_at')
                ->orWhere('website_products.promotion_live', true))
            ->whereRaw("website_products.promotion_live IS DISTINCT FROM {$applies}", $bindings)
            ->select('website_products.id')
            ->chunkById(200, function (Collection $selections) use ($at, &$announced) {
                foreach ($selections as $selection) {
                    $announced += $this->announce($selection->id, $at) ? 1 : 0;
                }
            }, 'website_products.id', 'id');

        return $announced;
    }

    protected function announce(int $id, CarbonImmutable $at): bool
    {
        return DB::transaction(function () use ($id, $at) {
            /** @var WebsiteProduct|null $selection */
            $selection = WebsiteProduct::query()->whereKey($id)->lockForUpdate()->first();

            if ($selection === null) {
                return false;
            }

            $applies = $selection->promotionApplies($at);

            // Somebody saved it, or another run announced it, since the scan.
            if ($selection->promotion_live === $applies) {
                return false;
            }

            $selection->promotion_live = $applies;
            $selection->save();

            if ($selection->status === WebsiteProductStatus::Published) {
                $this->sync->handle($selection, WebhookEvent::ProductPriceChanged);
            }

            return true;
        });
    }
}
