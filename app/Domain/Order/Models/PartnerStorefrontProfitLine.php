<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One order line's part in a Storefront profit: how it was costed, and what it
 * comes to for the units that were kept.
 *
 * @property int $id
 * @property int $partner_storefront_profit_id
 * @property int $order_item_id
 * @property string $currency_code
 * @property string $cost_basis `platform_price`, `fifo_lots`, `historical_single_cost` or `finance_correction`
 * @property int $quantity
 * @property int $returned_quantity
 * @property Money $selling_amount
 * @property Money|null $cost_amount
 * @property Money|null $profit_amount
 * @property string|null $unproven_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PartnerStorefrontProfit $profit
 * @property-read OrderItem $orderItem
 */
class PartnerStorefrontProfitLine extends Model
{
    /** Superseded 2026-11-28: lines worked out before then may still carry it. */
    public const BASIS_PLATFORM_PRICE = 'platform_price';

    /** Banij central stock: the wholesale price frozen on the line at placement. */
    public const BASIS_WHOLESALE_PRICE = 'wholesale_price';

    /** Nothing frozen: the product's wholesale price as the catalogue holds it. */
    public const BASIS_CATALOGUE_WHOLESALE_PRICE = 'catalogue_wholesale';

    public const BASIS_FIFO_LOTS = 'fifo_lots';

    public const BASIS_HISTORICAL_SINGLE_COST = 'historical_single_cost';

    public const BASIS_FINANCE_CORRECTION = 'finance_correction';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
            'selling_amount' => MoneyCast::class,
            'cost_amount' => MoneyCast::class,
            'profit_amount' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PartnerStorefrontProfit, $this>
     */
    public function profit(): BelongsTo
    {
        return $this->belongsTo(PartnerStorefrontProfit::class, 'partner_storefront_profit_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
