<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Enums\OfferStatus;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One Supplier's independently-priced, independently-stocked claim on one
 * catalogue product or variation (D25, P13-14, P13-15).
 *
 * **One product variation may carry several offers**, one per Supplier who
 * supplies it. Each keeps its own rate, its own availability, and can be
 * suspended without touching any other Supplier's offer on the same
 * variation — approving a second Supplier for a product a first Supplier
 * already supplies must never overwrite the first one's figures.
 *
 * `supplier_rate_minor` is commercially confidential in the way a wholesale
 * cost price is (D12, D25): visible only to the owning Supplier and to staff
 * holding `supplier_pricing.view`. `platform_rate_minor` is the only figure a
 * Client/Partner or a Partner Website ever sees.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $supplier_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $originating_listing_item_id
 * @property OfferStatus $status
 * @property bool $is_preferred
 * @property bool $wholesale_enabled
 * @property bool $dropshipping_enabled
 * @property Money $supplier_rate_minor
 * @property Money $platform_rate_minor
 * @property string $currency_code
 * @property int|null $activated_by
 * @property CarbonImmutable|null $activated_at
 * @property int|null $suspended_by
 * @property CarbonImmutable|null $suspended_at
 * @property-read Supplier $supplier
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read SupplierOfferStock|null $stock
 * @property-read Collection<int, SupplierOfferPriceChange> $priceHistory
 */
class SupplierOffer extends Model
{
    use HasPublicId, HasReference;

    protected $guarded = [];

    protected $attributes = [
        'status' => OfferStatus::Active->value,
        'is_preferred' => false,
        'wholesale_enabled' => false,
        'dropshipping_enabled' => false,
        'currency_code' => 'BDT',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'is_preferred' => 'boolean',
            'wholesale_enabled' => 'boolean',
            'dropshipping_enabled' => 'boolean',
            'supplier_rate_minor' => MoneyCast::class,
            'platform_rate_minor' => MoneyCast::class,
            'activated_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::SupplierOffer;
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<SupplierProductListingItem, $this>
     */
    public function originatingListingItem(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListingItem::class, 'originating_listing_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    /**
     * @return HasOne<SupplierOfferStock, $this>
     */
    public function stock(): HasOne
    {
        return $this->hasOne(SupplierOfferStock::class);
    }

    /**
     * @return HasMany<SupplierOfferPriceChange, $this>
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(SupplierOfferPriceChange::class)->orderByDesc('effective_from')->orderByDesc('id');
    }

    /**
     * @return HasMany<SupplierStockUpdate, $this>
     */
    public function stockUpdates(): HasMany
    {
        return $this->hasMany(SupplierStockUpdate::class)->orderByDesc('id');
    }

    /**
     * The platform margin — Feriwala's own figure, never exposed to a
     * Client/Partner or a Partner Website (D25).
     */
    public function platformMargin(): Money
    {
        return $this->platform_rate_minor->minus($this->supplier_rate_minor);
    }

    public function isActive(): bool
    {
        return $this->status === OfferStatus::Active;
    }
}
