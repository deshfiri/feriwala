<?php

namespace App\Domain\Inventory\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Inventory\PartnerStockCostLots;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One purchase's units in a Partner's own stock, and what each cost them: net of
 * the purchase line's discount, excluding tax. Consumed first in, first out.
 *
 * Everything but `remaining_quantity` and `issued_quantity` is frozen by the
 * database; those two move only through {@see PartnerStockCostLots}, alongside
 * the {@see PartnerStockLotMovement} that explains each change.
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property int $partner_stock_item_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $source_order_id
 * @property int|null $source_order_item_id
 * @property string $provenance `purchase`, `opening` or `adjustment`
 * @property int $purchased_quantity
 * @property int $remaining_quantity
 * @property string $currency_code
 * @property Money|null $net_cost
 * @property Money|null $unit_cost
 * @property bool $cost_is_known
 * @property int $issued_quantity
 * @property CarbonImmutable $purchased_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order|null $sourceOrder
 * @property-read OrderItem|null $sourceOrderItem
 */
class PartnerStockCostLot extends Model
{
    use HasPublicId;

    public const PROVENANCE_PURCHASE = 'purchase';

    public const PROVENANCE_OPENING = 'opening';

    public const PROVENANCE_ADJUSTMENT = 'adjustment';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_quantity' => 'integer',
            'remaining_quantity' => 'integer',
            'issued_quantity' => 'integer',
            'net_cost' => MoneyCast::class,
            'unit_cost' => MoneyCast::class,
            'cost_is_known' => 'boolean',
            'purchased_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function sourceOrderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'source_order_item_id');
    }

    /**
     * @return HasMany<PartnerStockLotMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PartnerStockLotMovement::class, 'partner_stock_cost_lot_id');
    }
}
