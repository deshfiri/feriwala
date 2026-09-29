<?php

namespace App\Domain\Order\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\ProductSourceLinkStatus;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-confirmed assertion that a Supplier offer or warehouse stock item
 * catalogued under a *different* product/variation fulfils orders for this
 * one (see the migration's own docblock).
 *
 * @property int $id
 * @property string $public_id
 * @property int $ordered_product_id
 * @property int|null $ordered_product_variant_id
 * @property AllocationSourceType $source_type
 * @property int|null $warehouse_stock_item_id
 * @property int|null $supplier_offer_id
 * @property ProductSourceLinkStatus $status
 * @property int $confirmed_by
 * @property CarbonImmutable $confirmed_at
 * @property string $confirmation_reason
 * @property int|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revocation_reason
 */
class ProductSourceLink extends Model
{
    use HasPublicId, HasStateMachine;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => AllocationSourceType::class,
            'status' => ProductSourceLinkStatus::class,
            'confirmed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function orderedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ordered_product_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function orderedVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'ordered_product_variant_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'warehouse_stock_item_id');
    }

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function supplierOffer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isActive(): bool
    {
        return $this->status === ProductSourceLinkStatus::Active;
    }

    /**
     * @param  Builder<ProductSourceLink>  $query
     * @return Builder<ProductSourceLink>
     */
    public function scopeForOrderedProduct(Builder $query, int $productId, ?int $variantId): Builder
    {
        return $query
            ->where('ordered_product_id', $productId)
            ->where('ordered_product_variant_id', $variantId)
            ->where('status', ProductSourceLinkStatus::Active);
    }
}
