<?php

namespace App\Domain\Website\Actions;

use App\Domain\Inventory\Queries\StockAvailability;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use Illuminate\Database\Eloquent\Collection;

/**
 * Near-real-time stock for storefronts (contract §5.2, §7.1, §17.2, P5-22, P5-25).
 *
 * Stock moves for reasons no website action knows about — a warehouse
 * adjustment, another channel's order, a reservation expiring — so rather than
 * threading a hook through every one of them, this comes round every few
 * minutes, works out each published product's availability, and tells the
 * storefront **only what changed** since it was last told:
 * `inventory.updated`, and `inventory.out_of_stock` the moment a product's last
 * unit goes.
 *
 * What was last sent is kept on the selection, so a quiet catalogue sends
 * nothing. Availability only — never a warehouse, never a reservation (§5.2).
 */
class SyncWebsiteInventory
{
    public function __construct(
        protected StockAvailability $stock,
        protected PublishWebsiteEvent $publish,
    ) {}

    public function handle(): int
    {
        $sent = 0;

        Website::query()
            ->whereIn('id', WebsiteWebhookEndpoint::query()->where('is_active', true)->select('website_id'))
            ->with('businessAccount')
            ->chunkById(50, function (Collection $websites) use (&$sent) {
                foreach ($websites as $website) {
                    if ($website->status->acceptsSync()) {
                        $sent += $this->forWebsite($website);
                    }
                }
            });

        return $sent;
    }

    public function forWebsite(Website $website): int
    {
        $sent = 0;

        WebsiteProduct::query()
            ->where('website_id', $website->id)
            ->where('status', WebsiteProductStatus::Published->value)
            ->with('product')
            ->chunkById(100, function (Collection $selections) use ($website, &$sent) {
                foreach ($selections as $selection) {
                    $availability = collect($this->stock->forProduct($selection->product, $website->businessAccount))
                        ->map(fn (array $unit) => [
                            'sku' => $unit['sku'],
                            'in_stock' => $unit['in_stock'],
                            'quantity' => $unit['quantity'],
                        ])
                        ->values()
                        ->all();

                    $previous = $selection->getAttribute('synced_availability');

                    // jsonb keeps its own key order, so compare the units, not the
                    // way they were written.
                    if (is_array($previous) && $this->sameUnits($previous, $availability)) {
                        continue;
                    }

                    $data = [
                        'id' => $selection->product->public_id,
                        'sku' => $selection->product->sku,
                        'availability' => $availability,
                    ];

                    $wasInStock = is_array($previous) && collect($previous)->contains(fn (array $unit) => $unit['in_stock'] ?? false);
                    $isInStock = collect($availability)->contains(fn (array $unit) => $unit['in_stock']);

                    $this->publish->handle($website, WebhookEvent::InventoryUpdated, $data, 'website_product_stock', $selection->id);

                    if (! $isInStock && ($wasInStock || $previous === null)) {
                        $this->publish->handle($website, WebhookEvent::InventoryOutOfStock, $data, 'website_product_stock', $selection->id);
                    }

                    // Stock is not the product changing: `updated_at` is left alone.
                    WebsiteProduct::query()->whereKey($selection->id)->toBase()->update([
                        'synced_availability' => json_encode($availability),
                    ]);

                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * @param  array<int, mixed>  $previous
     * @param  array<int, array{sku: string, in_stock: bool, quantity: int}>  $current
     */
    protected function sameUnits(array $previous, array $current): bool
    {
        if (count($previous) !== count($current)) {
            return false;
        }

        foreach ($current as $index => $unit) {
            $before = $previous[$index] ?? null;

            if (! is_array($before)
                || ($before['sku'] ?? null) !== $unit['sku']
                || ($before['in_stock'] ?? null) !== $unit['in_stock']
                || ($before['quantity'] ?? null) !== $unit['quantity']) {
                return false;
            }
        }

        return true;
    }
}
