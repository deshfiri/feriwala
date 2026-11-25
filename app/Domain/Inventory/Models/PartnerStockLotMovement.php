<?php

namespace App\Domain\Inventory\Models;

use App\Casts\MoneyCast;
use App\Domain\Inventory\PartnerStockCostLots;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to a cost lot: units in, out to a sale, back from one. Append-only
 * in the database; written only by {@see PartnerStockCostLots}.
 *
 * @property int $id
 * @property int $partner_stock_cost_lot_id
 * @property int $partner_stock_item_id
 * @property string $type `received`, `allocated`, `released`, `returned`, `reversed` or `adjusted`
 * @property int $quantity signed: negative takes units out of the lot
 * @property string $currency_code
 * @property Money|null $cost what those units cost, when the lot's cost is known
 * @property int|null $stock_reservation_id
 * @property int|null $order_item_id
 * @property int|null $partner_stock_movement_id
 * @property string $idempotency_key
 * @property string|null $reason
 * @property int|null $actor_id
 * @property CarbonImmutable $created_at
 * @property-read PartnerStockCostLot $lot
 */
class PartnerStockLotMovement extends Model
{
    public const TYPE_RECEIVED = 'received';

    public const TYPE_ALLOCATED = 'allocated';

    public const TYPE_RELEASED = 'released';

    public const TYPE_RETURNED = 'returned';

    public const TYPE_REVERSED = 'reversed';

    public const TYPE_ADJUSTED = 'adjusted';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cost' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PartnerStockCostLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(PartnerStockCostLot::class, 'partner_stock_cost_lot_id');
    }
}
