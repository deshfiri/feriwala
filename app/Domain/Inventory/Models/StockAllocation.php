<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Inventory\StockAllocations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Central stock set aside for one business account, in one warehouse (§19:
 * user-allocated stock, P3-30).
 *
 * `quantity` is what is still set aside and not yet reserved by one of the
 * account's orders. The item's `allocated` figure is the sum of these, which the
 * database checks at commit. Only {@see StockAllocations} changes it, under the
 * item's row lock.
 *
 * @property int $id
 * @property string $public_id
 * @property int $stock_item_id
 * @property int $business_account_id
 * @property int $quantity
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StockItem $item
 * @property-read BusinessAccount $account
 */
class StockAllocation extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id')->withTrashed();
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
