<?php

namespace App\Domain\Supplier\Models;

use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Supplier\Enums\FulfilmentCommitmentStatus;
use App\Models\User;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Supplier's commitment to fulfil one order line from an on_demand or
 * pre_order offer, with no physical stock behind it (Supplier Bulk Product
 * Listing batch, correction 7).
 *
 * This row **is** the capacity reservation -- see {@see
 * \App\Domain\Supplier\Actions\RecordSupplierFulfilmentCommitment}, which
 * locks the offer and counts non-terminal commitments against its declared
 * `fulfilment_capacity` before creating one. Exactly one commitment exists
 * per {@see OrderItemAllocation} (a database-enforced 1:1), created only for
 * a non-ready-stock allocation.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $order_item_allocation_id
 * @property int $supplier_offer_id
 * @property int $quantity
 * @property FulfilmentCommitmentStatus $status
 * @property CarbonImmutable|null $due_at
 * @property int|null $confirmed_by
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $failed_reason
 * @property CarbonImmutable|null $failed_at
 * @property int|null $cancelled_by
 * @property string|null $cancelled_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property-read OrderItemAllocation $allocation
 * @property-read SupplierOffer $offer
 * @property-read User|null $confirmedBy
 * @property-read User|null $cancelledBy
 * @property-read Collection<int, SupplierFulfilmentCommitmentStatusChange> $statusHistory
 */
class SupplierFulfilmentCommitment extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    protected $attributes = [
        'status' => FulfilmentCommitmentStatus::AwaitingConfirmation->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FulfilmentCommitmentStatus::class,
            'quantity' => 'integer',
            'due_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::SupplierFulfilmentCommitment;
    }

    /**
     * The set of statuses that still hold capacity against the offer's
     * `fulfilment_capacity` -- every state before the commitment reaches a
     * terminal one.
     *
     * @return list<FulfilmentCommitmentStatus>
     */
    public static function nonTerminalStatuses(): array
    {
        return array_values(array_filter(
            FulfilmentCommitmentStatus::cases(),
            fn (FulfilmentCommitmentStatus $status) => ! $status->isTerminal(),
        ));
    }

    /**
     * @return BelongsTo<OrderItemAllocation, $this>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(OrderItemAllocation::class, 'order_item_allocation_id');
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
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<SupplierFulfilmentCommitmentStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupplierFulfilmentCommitmentStatusChange::class)->orderBy('id');
    }
}
