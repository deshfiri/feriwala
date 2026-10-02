<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Catalog\Actions\ManageProducts;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Supplier\Actions\DecideSupplierListing;
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
 * @property int|null $available_quantity
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
 * @property int|null $proposed_net_weight_grams
 * @property int|null $proposed_shipping_weight_grams
 * @property string|null $proposed_length_cm
 * @property string|null $proposed_width_cm
 * @property string|null $proposed_height_cm
 * @property bool|null $proposed_ships_by_box
 * @property int|null $proposed_pieces_per_box
 * @property int|null $proposed_box_weight_grams
 * @property string|null $proposed_box_length_cm
 * @property string|null $proposed_box_width_cm
 * @property string|null $proposed_box_height_cm
 * @property bool|null $proposed_is_fragile
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
            'proposed_net_weight_grams' => 'integer',
            'proposed_shipping_weight_grams' => 'integer',
            'proposed_length_cm' => 'decimal:2',
            'proposed_width_cm' => 'decimal:2',
            'proposed_height_cm' => 'decimal:2',
            'proposed_ships_by_box' => 'boolean',
            'proposed_pieces_per_box' => 'integer',
            'proposed_box_weight_grams' => 'integer',
            'proposed_box_length_cm' => 'decimal:2',
            'proposed_box_width_cm' => 'decimal:2',
            'proposed_box_height_cm' => 'decimal:2',
            'proposed_is_fragile' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * What the Supplier proposed, mapped to the same keys {@see
     * \App\Domain\Catalog\Actions\ManageProducts}/{@see
     * \App\Domain\Catalog\Actions\ManageVariants} already accept -- a
     * proposal only, never written to the catalogue on its own (beta-
     * critical batch, Commit 1). {@see
     * \App\Domain\Supplier\Actions\DecideSupplierListing::approveItem()} is
     * the one place a reviewer's own confirmed or corrected figures, not
     * these, are written to the central Product or Variant being connected.
     *
     * @return array<string, mixed>
     */
    public function proposedLogistics(): array
    {
        return [
            'net_weight_grams' => $this->proposed_net_weight_grams,
            'shipping_weight_grams' => $this->proposed_shipping_weight_grams,
            'length_cm' => $this->proposed_length_cm,
            'width_cm' => $this->proposed_width_cm,
            'height_cm' => $this->proposed_height_cm,
            'ships_by_box' => $this->proposed_ships_by_box,
            'pieces_per_box' => $this->proposed_pieces_per_box,
            'box_weight_grams' => $this->proposed_box_weight_grams,
            'box_length_cm' => $this->proposed_box_length_cm,
            'box_width_cm' => $this->proposed_box_width_cm,
            'box_height_cm' => $this->proposed_box_height_cm,
            'is_fragile' => $this->proposed_is_fragile,
        ];
    }

    /**
     * The one validation ruleset for a Supplier's proposed logistics figures
     * -- shared between the single-listing and bulk-lot entry endpoints so
     * the two can never quietly drift apart (beta-critical batch, Commit 1).
     * Every rule is nullable: a Supplier is never required to propose
     * logistics data.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function proposedLogisticsValidationRules(string $prefix = 'items.*.proposed_'): array
    {
        return self::logisticsFieldRules($prefix);
    }

    /**
     * The validation ruleset for a reviewer's own confirmed (or corrected)
     * logistics figures at listing decision -- the same bounds, on the plain
     * field names {@see ManageProducts}/{@see
     * \App\Domain\Catalog\Actions\ManageVariants} accept, since this is what
     * {@see DecideSupplierListing::
     * confirmLogistics()} passes straight through to them.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function confirmedLogisticsValidationRules(string $prefix = 'items.*.logistics.'): array
    {
        return self::logisticsFieldRules($prefix);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function logisticsFieldRules(string $prefix): array
    {
        return [
            "{$prefix}net_weight_grams" => ['nullable', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}shipping_weight_grams" => ['nullable', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}length_cm" => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            "{$prefix}width_cm" => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            "{$prefix}height_cm" => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            "{$prefix}ships_by_box" => ['nullable', 'boolean'],
            "{$prefix}pieces_per_box" => ['nullable', 'integer', 'min:1', 'max:100000'],
            "{$prefix}box_weight_grams" => ['nullable', 'integer', 'min:1', 'max:1000000'],
            "{$prefix}box_length_cm" => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            "{$prefix}box_width_cm" => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            "{$prefix}box_height_cm" => ['nullable', 'numeric', 'min:0.01', 'max:1000'],
            "{$prefix}is_fragile" => ['nullable', 'boolean'],
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
