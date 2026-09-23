<?php

namespace App\Domain\Supplier\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One applied change to a Supplier offer's availability (D25, P13-17).
 * Append-only, mirroring the shape `stock_movements` already uses for
 * central warehouse stock — a distinguishable, parallel record, not a shared
 * one.
 *
 * @property int $id
 * @property int $supplier_offer_id
 * @property int|null $supplier_stock_update_id
 * @property int $quantity_before
 * @property int $quantity_after
 * @property string $source
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $reason
 * @property int|null $moved_quantity
 * @property string|null $from_bucket
 * @property string|null $to_bucket
 * @property array<string, int>|null $buckets_before
 * @property array<string, int>|null $buckets_after
 * @property int|null $stock_reservation_id
 * @property string|null $idempotency_key
 * @property CarbonImmutable $created_at
 * @property-read SupplierOffer $offer
 */
class SupplierStockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
            'moved_quantity' => 'integer',
            'buckets_before' => 'array',
            'buckets_after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }

    public function delta(): int
    {
        return $this->quantity_after - $this->quantity_before;
    }
}
