<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Enums\ListingItemStatus;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property Money $supplier_rate_minor
 * @property string $currency_code
 * @property int $available_quantity
 * @property int $minimum_supply_quantity
 * @property int|null $lead_time_days
 * @property string|null $warranty
 * @property string|null $return_conditions
 * @property ListingItemStatus $status
 * @property string|null $decision_note
 * @property int|null $connected_product_variant_id
 * @property int|null $supplier_offer_id
 * @property-read SupplierProductListing $listing
 * @property-read ProductVariant|null $connectedVariant
 * @property-read SupplierOffer|null $offer
 */
class SupplierProductListingItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => ListingItemStatus::Pending->value,
        'currency_code' => 'BDT',
        'minimum_supply_quantity' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_rate_minor' => MoneyCast::class,
            'available_quantity' => 'integer',
            'minimum_supply_quantity' => 'integer',
            'lead_time_days' => 'integer',
            'status' => ListingItemStatus::class,
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
}
