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

    public const PAGE_SIZE = 40;

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

        return $this->summarise($this->matching($term, $excludePublicIds)->limit(self::SEARCH_LIMIT)->get());
    }

    /**
     * Every Product, alphabetically, page by page, optionally narrowed by a
     * term — for a picker that opens already listing the catalogue. Nothing is
     * ever left out: the next page is always there until `has_more` says not.
     *
     * @return array{data: list<array<string, mixed>>, has_more: bool}
     */
    public function listing(string $term, int $page): array
    {
        $term = trim($term);
        $page = max(1, $page);

        $rows = $this->matching($term, [])
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        return [
            'data' => $this->summarise($rows->take(self::PAGE_SIZE)),
            'has_more' => $rows->count() > self::PAGE_SIZE,
        ];
    }

    /**
     * @param  list<string>  $excludePublicIds
     * @return Builder<Product>
     */
    protected function matching(string $term, array $excludePublicIds): Builder
    {
        $pattern = '%'.addcslashes($term, '%_\\').'%';

        return Product::query()
            ->when($excludePublicIds !== [], fn (Builder $query) => $query->whereNotIn('public_id', $excludePublicIds))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'ilike', $pattern)
                ->orWhere('sku', 'ilike', $pattern)
                ->orWhere('barcode', 'ilike', $pattern)))
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    protected function summarise(Collection $products): array
    {
        $summaries = $this->for($products);

        return array_values(array_map(fn (Product $product) => $summaries[$product->id], $products->values()->all()));
    }
}
