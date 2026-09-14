<?php

namespace App\Domain\Inventory\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Inventory\Enums\ReservationKind;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of central stock set aside for an order not yet confirmed (§19.1).
 *
 * Its status moves only through {@see HasStateMachine::transitionTo()}, and
 * only the reservation service moves it, in the same transaction as the stock
 * movement that takes the units back or on.
 *
 * @property int $id
 * @property string $public_id
 * @property int $stock_item_id
 * @property int $quantity
 * @property ReservationKind $kind
 * @property StockReservationStatus $status
 * @property string $reference
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable|null $released_at
 * @property string|null $release_reason
 * @property int|null $overridden_by
 * @property int|null $business_account_id
 * @property int|null $stock_allocation_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read StockItem $item
 * @property-read User|null $overrider
 * @property-read BusinessAccount|null $account
 * @property-read StockAllocation|null $allocation
 */
class StockReservation extends Model
{
    use HasPublicId, HasStateMachine;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'kind' => ReservationKind::class,
            'status' => StockReservationStatus::class,
            'expires_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    /**
     * The account whose order this is, when it came from one (P3-30).
     *
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id')->withTrashed();
    }

    /**
     * The allocation it drew on, when it did not draw on shared stock (P3-30).
     *
     * @return BelongsTo<StockAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(StockAllocation::class, 'stock_allocation_id');
    }

    /**
     * Reservations still holding units.
     *
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StockReservationStatus::Active->value);
    }
}
