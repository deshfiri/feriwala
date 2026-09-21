<?php

namespace App\Domain\Supplier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current availability quantity for one Supplier offer (D25, P13-17).
 *
 * A source distinguishable from central warehouse stock (`stock_items`) —
 * never written by `StockLedger`, and never read by it. One row per offer;
 * the applied-change history is `SupplierStockMovement`.
 *
 * @property int $supplier_offer_id
 * @property int $quantity
 * @property-read SupplierOffer $offer
 */
class SupplierOfferStock extends Model
{
    protected $table = 'supplier_offer_stock';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }
}
