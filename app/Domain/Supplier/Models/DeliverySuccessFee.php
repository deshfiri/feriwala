<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Delivery Success Fee, charged once against a supplier's own payable
 * the moment its order reached Delivered (D-new).
 *
 * A decision row, immutable from the moment it exists — see the migration's
 * own docblock. `rate_percent` is frozen here precisely so a later change to
 * the configured percentage never rewrites a fee already charged.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $supplier_id
 * @property int $supplier_payable_id
 * @property Money $base_amount
 * @property string $currency_code
 * @property string $rate_percent
 * @property Money $fee_amount
 * @property int $supplier_ledger_entry_id
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read OrderItem $orderItem
 * @property-read Supplier $supplier
 * @property-read SupplierPayable $payable
 * @property-read SupplierLedgerEntry $ledgerEntry
 */
class DeliverySuccessFee extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_amount' => MoneyCast::class,
            'fee_amount' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException('A Delivery Success Fee cannot be changed: it is the record of a charge already made.');
        });

        static::deleting(function (): never {
            throw new \RuntimeException('A Delivery Success Fee cannot be deleted: it is the record of a charge already made.');
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierPayable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(SupplierPayable::class, 'supplier_payable_id');
    }

    /**
     * @return BelongsTo<SupplierLedgerEntry, $this>
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(SupplierLedgerEntry::class, 'supplier_ledger_entry_id');
    }
}
