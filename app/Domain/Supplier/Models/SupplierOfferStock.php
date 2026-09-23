<?php

namespace App\Domain\Supplier\Models;

use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Supplier\SupplierStockLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * The stock a Supplier's offer can supply, by bucket (D25, P13-17, P13-28).
 *
 * A source distinguishable from central warehouse stock (`stock_items`) —
 * never written by `StockLedger`, and never read by it. One row per offer; the
 * applied-change history is `SupplierStockMovement`, and every figure here is
 * moved only by {@see SupplierStockLedger}, which holds
 * the row lock and refuses to drive a bucket negative (the database refuses it
 * too).
 *
 * It keeps the same buckets central stock has, so the reservation lifecycle
 * reads the same way: `quantity` is the **approved available** figure — the one
 * a Supplier submits and staff approve — and a reservation moves units from it
 * to reserved, on to processing when the order is confirmed, and to sold when
 * delivery is recorded.
 *
 * @property int $id
 * @property int $supplier_offer_id
 * @property int $quantity
 * @property int $reserved_quantity
 * @property int $processing_quantity
 * @property int $sold_quantity
 * @property int $returned_quantity
 * @property int $damaged_quantity
 * @property-read SupplierOffer $offer
 */
class SupplierOfferStock extends Model
{
    protected $table = 'supplier_offer_stock';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'processing_quantity' => 'integer',
        'sold_quantity' => 'integer',
        'returned_quantity' => 'integer',
        'damaged_quantity' => 'integer',
    ];

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }

    /**
     * The column that holds one bucket. Central stock names its columns after
     * its buckets; here the available bucket is the long-standing `quantity`.
     *
     * @throws InvalidArgumentException for a bucket a Supplier's stock does not keep
     */
    public static function column(StockBucket $bucket): string
    {
        return match ($bucket) {
            StockBucket::Available => 'quantity',
            StockBucket::Reserved => 'reserved_quantity',
            StockBucket::Processing => 'processing_quantity',
            StockBucket::Sold => 'sold_quantity',
            StockBucket::Returned => 'returned_quantity',
            StockBucket::Damaged => 'damaged_quantity',
            StockBucket::Allocated => throw new InvalidArgumentException('Supplier stock is never allocated to an account.'),
        };
    }

    /**
     * Every bucket's figure, keyed as the central ledger keys them.
     *
     * @return array<string, int>
     */
    public function buckets(): array
    {
        return [
            StockBucket::Available->value => $this->quantity,
            StockBucket::Reserved->value => $this->reserved_quantity,
            StockBucket::Processing->value => $this->processing_quantity,
            StockBucket::Sold->value => $this->sold_quantity,
            StockBucket::Returned->value => $this->returned_quantity,
            StockBucket::Damaged->value => $this->damaged_quantity,
        ];
    }
}
