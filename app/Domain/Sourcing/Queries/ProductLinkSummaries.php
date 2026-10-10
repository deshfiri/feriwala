<?php

namespace App\Domain\Sourcing\Queries;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\ProductMediaStore;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Supplier\Models\SupplierOffer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What staff need to recognise a Product when deciding whether it is the same
 * as another: image, title, BPC, SKU/barcode, a variation summary and how many
 * Supplier and Warehouse sources already hang off it.
 *
 * **Staff-only.** The source counts hint at who supplies a Product, so this
 * never goes into a Client, Partner or Storefront payload. It carries no rates.
 */
class ProductLinkSummaries
{
    public const VARIANT_LABELS_SHOWN = 3;

    public const SEARCH_LIMIT = 15;

    public function __construct(protected ProductMediaStore $media) {}

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array<string, mixed>> keyed by Product database id
     */
    public function for(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id')->all();

        $images = ProductMedia::query()
            ->whereIn('product_id', $ids)
            ->where('type', ProductMedia::TYPE_IMAGE)
            ->where('position', 1)
            ->get()
            ->keyBy('product_id');

        $variants = ProductVariant::query()
            ->with('values')
            ->whereIn('product_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $offerCounts = SupplierOffer::query()
            ->whereIn('product_id', $ids)
            ->where('status', 'active')
            ->selectRaw('product_id, count(*) as aggregate')
            ->groupBy('product_id')
            ->pluck('aggregate', 'product_id');

        $warehouseCounts = StockItem::query()
            ->whereIn('product_id', $ids)
            ->whereHas('warehouse', fn (Builder $query) => $query->where('is_active', true))
            ->selectRaw('product_id, count(*) as aggregate')
            ->groupBy('product_id')
            ->pluck('aggregate', 'product_id');

        $summaries = [];

        foreach ($products as $product) {
            $own = $variants->get($product->id, collect());
            $image = $images->get($product->id);

            $summaries[$product->id] = [
                'id' => $product->public_id,
                'name' => $product->name,
                'bpc' => $product->sku,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'status' => $product->status->value,
                'image_url' => $image === null ? null : $this->media->url($image->path, $image->disk),
                'variant_count' => $own->count(),
                'variant_labels' => $own->take(self::VARIANT_LABELS_SHOWN)->map(fn (ProductVariant $variant) => $variant->label())->values()->all(),
                'supplier_sources' => (int) ($offerCounts[$product->id] ?? 0),
                'warehouse_sources' => (int) ($warehouseCounts[$product->id] ?? 0),
            ];
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>
     */
    public function one(Product $product): array
    {
        return $this->for(collect([$product]))[$product->id];
    }

    /**
     * Products whose BPC, title, SKU or barcode matches — a suggestion list
     * only. Nothing in it is selected, linked or ranked as "probably the same".
     *
     * @param  list<string>  $excludePublicIds
     * @return list<array<string, mixed>>
     */
    public function search(string $term, array $excludePublicIds = []): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $pattern = '%'.addcslashes($term, '%_\\').'%';

        $products = Product::query()
            ->when($excludePublicIds !== [], fn (Builder $query) => $query->whereNotIn('public_id', $excludePublicIds))
            ->where(fn (Builder $query) => $query
                ->where('name', 'ilike', $pattern)
                ->orWhere('sku', 'ilike', $pattern)
                ->orWhere('barcode', 'ilike', $pattern))
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        $summaries = $this->for($products);

        return array_values(array_map(fn (Product $product) => $summaries[$product->id], $products->all()));
    }
}
