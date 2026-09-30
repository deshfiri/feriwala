<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Domain\Supplier\Enums\SupplyMode;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One proposed variation on a Supplier Product Listing Request (D25, P13-12).
 *
 * Decided independently of its siblings — a listing of three variations may
 * end up with one approved, one rejected and one sent back for correction,
 * and the listing's own status rolls that up (`ListingStatus::PartiallyApproved`).
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_product_listing_id
 * @property string|null $variant_label
 * @property string $supplier_sku
 * @property Money $supplier_rate
 * @property string $currency_code
 * @property int $available_quantity
 * @property int $minimum_supply_quantity
 * @property int|null $lead_time_days
 * @property string|null $warranty
 * @property string|null $return_conditions
 * @property ListingItemStatus $status
 * @property string|null $decision_note
 * @property SupplyMode $supply_mode
 * @property int|null $fulfilment_capacity
 * @property CarbonImmutable|null $expected_availability_at
 * @property int|null $connected_product_variant_id
 * @property int|null $supplier_offer_id
 * @property-read SupplierProductListing $listing
 * @property-read ProductVariant|null $connectedVariant
 * @property-read SupplierOffer|null $offer
 * @property-read Collection<int, SupplierListingMedia> $variantMedia
 * @property-read Collection<int, ProductAttributeValue> $attributeValues
 */
class SupplierProductListingItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => ListingItemStatus::Pending->value,
        'currency_code' => 'BDT',
        'minimum_supply_quantity' => 1,
        'supply_mode' => SupplyMode::ReadyStock->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_rate' => MoneyCast::class,
            'available_quantity' => 'integer',
            'minimum_supply_quantity' => 'integer',
            'lead_time_days' => 'integer',
            'status' => ListingItemStatus::class,
            'supply_mode' => SupplyMode::class,
            'fulfilment_capacity' => 'integer',
            'expected_availability_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierProductListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(SupplierProductListing::class, 'supplier_product_listing_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function connectedVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'connected_product_variant_id');
    }

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }

    /**
     * A variant-specific override image -- distinct from the product
     * entry's own {@see SupplierProductListing::media()}.
     *
     * @return HasMany<SupplierListingMedia, $this>
     */
    public function variantMedia(): HasMany
    {
        return $this->hasMany(SupplierListingMedia::class, 'supplier_product_listing_item_id')->orderBy('position');
    }

    /**
     * The structured option values this variation was proposed with --
     * Size, Colour and so on, selected only from the catalogue's own
     * existing {@see ProductAttributeValue} rows, never a Supplier-invented
     * one.
     *
     * @return BelongsToMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'supplier_product_listing_item_attribute_values',
            'supplier_product_listing_item_id',
            'product_attribute_value_id',
        )->withPivot('product_attribute_id');
    }
}
