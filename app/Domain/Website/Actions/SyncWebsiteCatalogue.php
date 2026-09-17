<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCategory;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Bringing a storefront's copy of its catalogue up to date
 * (contract §7, §17.2, P5-22, P5-25).
 *
 * Two of §17.2's four modes live here:
 *
 *   - **Scheduled** — {@see sweep()} runs on the scheduler and catches what no
 *     partner action announced: Feriwala changing a product centrally, or a
 *     selection whose last event never went out. A product whose catalogue
 *     record changed after the shop last synchronised is announced again.
 *   - **Manual** — {@see handle()} with `$everything`, pressed by a person who
 *     wants the storefront told about every published product and its
 *     categories now.
 *
 * Real-time is the partner's own actions announcing themselves as they happen;
 * a person retrying a failed delivery is the fourth.
 */
class SyncWebsiteCatalogue
{
    public function __construct(
        protected SyncWebsiteProduct $product,
        protected PublishWebsiteEvent $publish,
    ) {}

    /**
     * @return int the number of products announced
     */
    public function handle(Website $website, bool $everything = false, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $announced = 0;

        WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('status', WebsiteProductStatus::Published->value)
            ->with(['website', 'product'])
            ->when(! $everything, fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->where('sync_status', WebsiteSyncStatus::Pending->value)
                    ->orWhereNull('last_synced_at')
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->whereColumn('products.updated_at', '>', 'website_products.last_synced_at')))
                // A delivery already on its way is left to finish: announcing
                // again every sweep while a storefront is down would stack a
                // new retry schedule per product every fifteen minutes. A
                // failed one waits for a person (P5-26).
                ->whereNotExists(fn (QueryBuilder $delivery) => $delivery
                    ->selectRaw('1')
                    ->from('webhook_deliveries')
                    ->where('webhook_deliveries.subject_type', 'website_product')
                    ->whereColumn('webhook_deliveries.subject_id', 'website_products.id')
                    ->whereIn('webhook_deliveries.state', [
                        WebhookDeliveryState::Pending->value,
                        WebhookDeliveryState::Retrying->value,
                    ])))
            ->chunkById(100, function (Collection $selections) use (&$announced) {
                foreach ($selections as $selection) {
                    if ($this->product->handle($selection, WebhookEvent::ProductUpdated) !== null) {
                        $announced++;
                    }
                }
            });

        if ($everything) {
            $this->categories($website);
        }

        return $announced;
    }

    /**
     * Every website with somewhere to be told, on the scheduler.
     */
    public function sweep(): int
    {
        $announced = 0;

        Website::query()
            ->whereIn('id', WebsiteWebhookEndpoint::query()->where('is_active', true)->select('website_id'))
            ->chunkById(50, function (Collection $websites) use (&$announced) {
                foreach ($websites as $website) {
                    if ($website->status->acceptsSync()) {
                        $announced += $this->handle($website);
                    }
                }
            });

        return $announced;
    }

    /**
     * The shop's arrangement changed (contract §7.1 `category.updated`).
     */
    public function categories(Website $website): void
    {
        $this->publish->handle(
            $website,
            WebhookEvent::CategoryUpdated,
            [
                'categories' => WebsiteCategory::query()
                    ->where('website_id', $website->id)
                    ->arranged()
                    ->get()
                    ->map(fn (WebsiteCategory $category) => [
                        'id' => $category->public_id,
                        'slug' => $category->slug,
                        'is_active' => $category->is_active,
                        'updated_at' => $category->updated_at->toIso8601String(),
                    ])
                    ->all(),
            ],
            'website',
            $website->id,
        );
    }
}
