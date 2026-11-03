<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Order\Enums\ReceivableComponent;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One component of a Store Order's platform receivable (D36).
 *
 * Append-only. Carries what a cancellation or a partial return needs to work
 * from: the component, the order line it belongs to, the quantity, the exact
 * amount and the rule it followed — never today's prices.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_platform_receivable_id
 * @property int|null $order_item_id
 * @property ReceivableComponent $component
 * @property string $component_key
 * @property string|null $basis `platform_retained_amount` or `product_cost`: which frozen figure a product line came from
 * @property string|null $tax_mode `exclusive` or `inclusive`
 * @property string $description
 * @property int $quantity
 * @property Money|null $unit_amount
 * @property Money $line_amount what this line adds to the receivable
 * @property Money|null $assessed_amount the tax as it was assessed, on a tax component; may exceed `line_amount` when the tax was already inside the price
 * @property string $currency_code
 * @property string $rule_reference
 * @property CarbonImmutable $created_at
 * @property-read OrderPlatformReceivable $receivable
 * @property-read OrderItem|null $orderItem
 */
class OrderPlatformReceivableLine extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'component' => ReceivableComponent::class,
            'quantity' => 'integer',
            'unit_amount' => MoneyCast::class,
            'line_amount' => MoneyCast::class,
            'assessed_amount' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderPlatformReceivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(OrderPlatformReceivable::class, 'order_platform_receivable_id');
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
