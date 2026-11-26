<?php

namespace App\Domain\Courier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Courier\Actions\IssueShipmentLabel;
use App\Domain\Courier\Actions\ReissueShipmentLabel;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Models\Order;
use App\Domain\Website\Models\Website;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a parcel's shipping label: the delivery snapshot frozen when
 * the courier consignment was confirmed ({@see IssueShipmentLabel}).
 *
 * Append-only in the database. A correction is a new version
 * ({@see ReissueShipmentLabel}); the version it replaces keeps every column
 * and only gains when and why it was superseded. Both print sizes render from
 * these columns, so a later change to the Storefront's logo or name, the
 * customer, the product or the warehouse never reaches a label already issued.
 *
 * Carries only what delivery needs. No email, no Partner figure, no cost,
 * profit, receivable, note or credential is ever written here.
 *
 * @property int $id
 * @property string $public_id
 * @property int $shipment_id
 * @property int $order_id
 * @property int $website_id
 * @property int $warehouse_id
 * @property int $version
 * @property string $courier_provider_code
 * @property string $courier_provider_name
 * @property string|null $consignment_id
 * @property string|null $tracking_code
 * @property string $barcode_value
 * @property string $store_name
 * @property string|null $store_phone
 * @property string|null $store_accent_color
 * @property string|null $store_logo_disk
 * @property string|null $store_logo_path
 * @property string|null $store_logo_mime
 * @property string $recipient_name
 * @property string $recipient_phone
 * @property array<string, string|null> $delivery_address
 * @property string|null $delivery_instructions
 * @property string $currency_code
 * @property Money $cod_amount
 * @property bool $is_prepaid
 * @property int $parcel_number
 * @property int $parcel_count
 * @property int $package_count
 * @property int|null $item_quantity null when it cannot be stated exactly (two parcels from one warehouse)
 * @property list<array{name: string, variant: string|null, sku: string, quantity: int}>|null $items null when not stated exactly, and on labels issued before it was frozen
 * @property string|null $parcel_weight_kg recorded package weights summed; null unless every package has one
 * @property string $order_reference
 * @property string|null $storefront_order_reference
 * @property CarbonImmutable $order_placed_at
 * @property string $warehouse_code
 * @property string $warehouse_name
 * @property string $warehouse_contact_name
 * @property string $warehouse_phone
 * @property array<string, string|null> $warehouse_return_address
 * @property int|null $issued_by
 * @property CarbonImmutable $issued_at
 * @property string|null $issue_reason
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $superseded_reason
 * @property int|null $superseded_by_label_id
 * @property-read Shipment $shipment
 * @property-read Order $order
 * @property-read Website $website
 * @property-read Warehouse $warehouse
 * @property-read User|null $issuedBy
 */
class ShipmentLabel extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'delivery_address' => 'array',
            'warehouse_return_address' => 'array',
            'cod_amount' => MoneyCast::class,
            'is_prepaid' => 'boolean',
            'parcel_number' => 'integer',
            'parcel_count' => 'integer',
            'package_count' => 'integer',
            'item_quantity' => 'integer',
            'items' => 'array',
            'parcel_weight_kg' => 'decimal:3',
            'order_placed_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /**
     * @param  Builder<ShipmentLabel>  $query
     * @return Builder<ShipmentLabel>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
