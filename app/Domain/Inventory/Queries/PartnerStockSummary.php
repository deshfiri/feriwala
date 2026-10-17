<?php

namespace App\Domain\Inventory\Queries;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use Illuminate\Support\Collection;

/**
 * What a Partner owns of each product, and where it is on sale (D34) — the figures
 * behind "My Products".
 *
 * Per unit (the product, or each exact variation): everything bought, what can
 * still be sold, what is held for orders not yet confirmed, what has gone to
 * customers, and what is back from a customer or damaged. Per storefront: whether
 * the product is on sale there, chosen but not yet on sale, or not chosen.
 *
 * Read-only, scoped to the one account it is asked about: another Partner's stock
 * and storefronts are never in an answer.
 */
class PartnerStockSummary
{
    /**
     * @param  iterable<int, int>  $productIds
     * @return array<int, array<string, mixed>> per product: its `units`, `totals` and `storefronts`
     */
    public function forProducts(BusinessAccount $account, iterable $productIds): array
    {
        $ids = collect($productIds)->map(fn (mixed $id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        /** @var Collection<int, PartnerStockItem> $items */
        $items = PartnerStockItem::query()
            ->where('business_account_id', $account->id)
            ->whereIn('product_id', $ids)
            ->with(['variant.values.attribute', 'product:id,sku'])
            ->orderBy('id')
            ->get();

        $websites = Website::query()
            ->where('business_account_id', $account->id)
            ->orderBy('id')
            ->get()
            ->reject(fn (Website $website) => $website->status->isTerminal())
            ->values();

        /** @var Collection<int, WebsiteProduct> $selections */
        $selections = WebsiteProduct::query()
            ->where('business_account_id', $account->id)
            ->whereIn('product_id', $ids)
            ->get(['id', 'public_id', 'website_id', 'product_id', 'status']);

        $answers = [];

        foreach ($ids as $productId) {
            $mine = $items->where('product_id', $productId)->values();

            $units = $mine->map(fn (PartnerStockItem $item) => [
                'sku' => $item->variant->sku ?? $item->product->sku,
                'variant' => $item->variant === null ? null : $item->variant->values
                    ->sortBy(fn (ProductAttributeValue $value) => $value->attribute->sort_order)
                    ->pluck('value')
                    ->implode(' / '),
                'purchased' => $item->purchased(),
                'available' => $item->available,
                'reserved' => $item->reserved,
                'sold' => $item->soldThroughStorefront(),
                'unavailable' => $item->unavailable(),
            ])->all();

            $answers[$productId] = [
                'units' => $units,
                'totals' => [
                    'purchased' => (int) array_sum(array_column($units, 'purchased')),
                    'available' => (int) array_sum(array_column($units, 'available')),
                    'reserved' => (int) array_sum(array_column($units, 'reserved')),
                    'sold' => (int) array_sum(array_column($units, 'sold')),
                    'unavailable' => (int) array_sum(array_column($units, 'unavailable')),
                ],
                'storefronts' => $websites->map(function (Website $website) use ($selections, $productId) {
                    /** @var WebsiteProduct|null $selection */
                    $selection = $selections->first(fn (WebsiteProduct $row) => $row->website_id === $website->id && $row->product_id === $productId);

                    return [
                        'id' => $website->public_id,
                        'name' => $website->name,
                        'state' => match (true) {
                            $selection === null => 'not_selected',
                            $selection->status === WebsiteProductStatus::Published => 'published',
                            default => 'selected',
                        },
                    ];
                })->all(),
            ];
        }

        return $answers;
    }
}
