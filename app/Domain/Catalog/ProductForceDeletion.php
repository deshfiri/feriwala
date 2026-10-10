<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * What a Super Admin Force Delete of one Product would touch.
 *
 * Read by the Trash screen's warning and by the action's audit record, so the
 * person confirming sees the same figures the system writes down. `blockers` is
 * deliberately narrow: only work that is still in flight — an order not yet
 * finished, stock committed to one, an active order allocation. History alone
 * never blocks.
 */
class ProductForceDeletion
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * @return array{removed: array<string, int>, deactivated: array<string, int>, preserved: array<string, int>, blockers: array<int, string>}
     */
    public function impact(Product $product): array
    {
        $variantIds = $this->database->table('product_variants')->where('product_id', $product->id)->pluck('id');
        $items = $this->database->table('stock_items')
            ->where('product_id', $product->id)
            ->orWhereIn('product_variant_id', $variantIds);
        $itemIds = $items->pluck('id');
        $offerIds = $this->database->table('supplier_offers')->where('product_id', $product->id)->pluck('id');

        $lines = fn () => $this->database->table('order_items')
            ->where(fn ($query) => $query->where('product_id', $product->id)->orWhereIn('product_variant_id', $variantIds));

        // Finished work is history; everything else is still in flight.
        $finished = [
            OrderStatus::Draft, OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Cancelled,
            OrderStatus::Returned, OrderStatus::PartiallyRefunded, OrderStatus::Refunded,
        ];

        $openStatuses = collect(OrderStatus::cases())
            ->reject(fn (OrderStatus $status) => in_array($status, $finished, true))
            ->map(fn (OrderStatus $status) => $status->value)
            ->all();

        $openLines = $lines()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', $openStatuses)
            ->count();

        $processing = (int) $this->database->table('stock_items')->whereIn('id', $itemIds)->sum('processing');

        $activeAllocations = $this->database->table('order_item_allocations')
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('source_product_id', $product->id)
                ->orWhereIn('linked_stock_item_id', $itemIds)
                ->orWhereIn('supplier_offer_id', $offerIds))
            ->count();

        $blockers = [];

        if ($openLines > 0) {
            $blockers[] = trans_choice('catalog.products.force_delete.blockers.open_orders', $openLines, ['count' => $openLines]);
        }

        if ($processing > 0) {
            $blockers[] = trans_choice('catalog.products.force_delete.blockers.processing', $processing, ['count' => $processing]);
        }

        if ($activeAllocations > 0) {
            $blockers[] = trans_choice('catalog.products.force_delete.blockers.allocations', $activeAllocations, ['count' => $activeAllocations]);
        }

        return [
            'removed' => [
                'variants' => $variantIds->count(),
                'media' => $this->database->table('product_media')->where('product_id', $product->id)->count(),
                'carts' => $this->database->table('cart_items')->where('product_id', $product->id)->count(),
                'price_tiers' => $this->database->table('product_price_tiers')->where('product_id', $product->id)->count(),
                'unused_stock_items' => $this->unusedStockItems($itemIds)->count(),
            ],
            'deactivated' => [
                'active_reservations' => $this->activeReservations($itemIds, $offerIds)->count(),
                'physical_units' => $this->physicalUnits($itemIds),
                'supplier_offers' => $this->database->table('supplier_offers')->where('product_id', $product->id)->where('status', 'active')->count(),
                'supplier_listings' => $this->database->table('supplier_product_listings')->where('connected_product_id', $product->id)->where('status', '!=', 'archived')->count(),
                'storefront_selections' => $this->database->table('website_products')->where('product_id', $product->id)->count(),
                'product_links' => $this->database->table('product_same_links')
                    ->where('status', 'active')
                    ->where(fn ($query) => $query->where('product_a_id', $product->id)->orWhere('product_b_id', $product->id))
                    ->count(),
                'sourcing_memberships' => $this->database->table('product_sourcing_group_products')->where('product_id', $product->id)->where('status', 'active')->count(),
                'source_links' => $this->database->table('product_source_links')->where('ordered_product_id', $product->id)->where('status', 'active')->count(),
            ],
            'preserved' => [
                'stock_movements' => $this->database->table('stock_movements')->where('product_id', $product->id)->count(),
                'order_lines' => $lines()->count(),
                'status_history' => $this->database->table('product_status_history')->where('product_id', $product->id)->count(),
                'supplier_offers' => $offerIds->count(),
            ],
            'blockers' => $blockers,
        ];
    }

    /**
     * Reservations still holding units for this Product, central or Supplier.
     *
     * @param  Collection<int, mixed>  $itemIds
     * @param  Collection<int, mixed>  $offerIds
     */
    public function activeReservations(Collection $itemIds, Collection $offerIds): Builder
    {
        $offerStockIds = $this->database->table('supplier_offer_stock')->whereIn('supplier_offer_id', $offerIds)->pluck('id');

        return $this->database->table('stock_reservations')
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereIn('stock_item_id', $itemIds)
                ->orWhereIn('supplier_offer_stock_id', $offerStockIds));
    }

    /**
     * @param  Collection<int, mixed>  $itemIds
     */
    public function physicalUnits(Collection $itemIds): int
    {
        return (int) $this->database->table('stock_items')->whereIn('id', $itemIds)
            ->selectRaw('COALESCE(SUM(available + reserved + returned + damaged + allocated), 0) AS units')
            ->value('units');
    }

    /**
     * Stock items nothing was ever recorded against, so removing them loses
     * nothing. Any item with a movement, adjustment, reservation, allocation or
     * source link stays, detached, as part of that history.
     *
     * @param  Collection<int, mixed>  $itemIds
     */
    public function unusedStockItems(Collection $itemIds): Builder
    {
        return $this->database->table('stock_items')
            ->whereIn('id', $itemIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stock_movements')->whereColumn('stock_movements.stock_item_id', 'stock_items.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stock_adjustments')->whereColumn('stock_adjustments.stock_item_id', 'stock_items.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stock_reservations')->whereColumn('stock_reservations.stock_item_id', 'stock_items.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stock_allocations')->whereColumn('stock_allocations.stock_item_id', 'stock_items.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('order_item_allocations')->whereColumn('order_item_allocations.linked_stock_item_id', 'stock_items.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('product_source_links')->whereColumn('product_source_links.warehouse_stock_item_id', 'stock_items.id'))
            ->where('available', 0)->where('reserved', 0)->where('processing', 0)
            ->where('returned', 0)->where('damaged', 0)->where('allocated', 0)->where('sold', 0);
    }
}
