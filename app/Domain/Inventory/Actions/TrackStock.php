<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Exceptions\InventoryRefused;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Policies\InventoryPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Start holding a SKU in a warehouse (§19).
 *
 * The item opens with every bucket at zero. Stock arrives through an adjustment
 * (P3-24), which records who received it and why — an item that opened with a
 * figure typed into it would be stock with no history.
 *
 * The unit is the variation when the product has variations: a warehouse holds
 * "12 of M, Navy", and a product-level figure beside per-variation ones would be
 * a second answer to how many there are.
 */
class TrackStock
{
    public function __construct(
        protected RecordAuditLog $audit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws InventoryRefused
     */
    public function handle(User $actor, Warehouse $warehouse, Product $product, ?ProductVariant $variant = null): StockItem
    {
        InventoryPolicy::authorize(InventoryPolicy::canEdit($actor));

        if (! $warehouse->is_active) {
            throw InventoryRefused::warehouseInactive();
        }

        if ($variant !== null && $variant->product_id !== $product->id) {
            throw InventoryRefused::variationNotOfProduct();
        }

        if ($variant === null && $product->variants()->exists()) {
            throw InventoryRefused::chooseVariation();
        }

        $existing = StockItem::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variant?->id)
            ->exists();

        if ($existing) {
            throw InventoryRefused::alreadyTracked();
        }

        try {
            return DB::transaction(function () use ($actor, $warehouse, $product, $variant) {
                $item = StockItem::create([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                ]);

                $this->audit->handle(new AuditEntry(
                    action: 'inventory.stock_tracked',
                    actorId: $actor->id,
                    auditableType: StockItem::class,
                    auditableId: $item->id,
                    after: [
                        'warehouse' => $warehouse->code,
                        'sku' => $variant !== null ? $variant->sku : $product->sku,
                    ],
                    module: 'inventory',
                ));

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests raced past the check above; the index settled it.
            throw InventoryRefused::alreadyTracked();
        }
    }
}
