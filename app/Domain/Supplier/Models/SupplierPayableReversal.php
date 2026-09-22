<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Order\Models\OrderReturnItem;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The compensating record for goods that came back against a Supplier payable
 * (D25, P13-22). Append-only, in the database as well as here: the payable is
 * never edited, so a reversal is a row of its own, exactly the quantity times
 * the payable's Supplier Rate, and the database refuses reversals that together
 * exceed the original.
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_payable_id
 * @property int $order_return_item_id
 * @property int $quantity
 * @property Money $amount_minor
 * @property string $currency_code
 * @property string $reason
 * @property string $idempotency_key
 * @property string|null $settlement_reversal_reference
 * @property CarbonImmutable $created_at
 * @property-read SupplierPayable $payable
 * @property-read OrderReturnItem $returnItem
 */
class SupplierPayableReversal extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'amount_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierPayable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(SupplierPayable::class, 'supplier_payable_id');
    }

    /**
     * @return BelongsTo<OrderReturnItem, $this>
     */
    public function returnItem(): BelongsTo
    {
        return $this->belongsTo(OrderReturnItem::class, 'order_return_item_id');
    }
}
