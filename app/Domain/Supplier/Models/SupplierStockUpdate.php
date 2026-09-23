<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Domain\Supplier\Enums\StockUpdateStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Supplier-submitted availability change, waiting on Admin authorization
 * (D25, P13-17). A Supplier may only ever submit; only an approval actually
 * moves the quantity, recorded as a `SupplierStockMovement`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_offer_id
 * @property int $requested_quantity
 * @property StockUpdateStatus $status
 * @property string|null $note
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property-read SupplierOffer $offer
 * @property-read User|null $decidedBy
 */
class SupplierStockUpdate extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => StockUpdateStatus::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_quantity' => 'integer',
            'status' => StockUpdateStatus::class,
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SupplierOffer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(SupplierOffer::class, 'supplier_offer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
