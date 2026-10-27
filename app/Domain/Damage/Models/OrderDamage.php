<?php

namespace App\Domain\Damage\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Cases\Models\CaseEvidence;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Enums\DamageSource;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Enums\DamageValuationBasis;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Website\Models\Website;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * A finding that goods are damaged, who bears the loss, and what it costs (D35).
 *
 * Never a standalone product record: it points at the order, the order line and,
 * where the goods came back, the return and its line. Everything that identifies
 * the case — whose it is, which line, how many units, what the goods were worth
 * when they were sold — is frozen when it is opened and refused by the database
 * afterwards. What moves is its status, through {@see transitionWithHistory()},
 * and what is decided about it: the inspection, who bears it and the approved
 * loss, which is itself fixed once decided.
 *
 * Never deleted, here and in the database.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $business_account_id
 * @property int|null $website_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int|null $order_return_id
 * @property int|null $order_return_item_id
 * @property DamageSource $source
 * @property bool $parcel_expected
 * @property bool $in_custody
 * @property StockBucket|null $held_bucket
 * @property int|null $warehouse_id
 * @property string $sku
 * @property string $product_name
 * @property string|null $variant_label
 * @property int $quantity
 * @property DamageCondition $damage_condition
 * @property DamageCause|null $cause
 * @property string|null $description
 * @property array<int, array<string, mixed>> $evidence
 * @property DamageStatus $status
 * @property ReturnInitiator $initiated_by_type
 * @property ReturnParty|null $responsible_party
 * @property string $currency_code
 * @property DamageValuationBasis $valuation_basis
 * @property Money|null $valuation_unit_price
 * @property Money $max_recoverable_value
 * @property array<string, mixed> $valuation_snapshot
 * @property Money|null $approved_loss_amount
 * @property string|null $approval_reason
 * @property string|null $write_off_reason
 * @property string|null $inspection_note
 * @property int|null $reported_by
 * @property CarbonImmutable $reported_at
 * @property int|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property int|null $inspected_by
 * @property CarbonImmutable|null $inspected_at
 * @property int|null $liability_decided_by
 * @property CarbonImmutable|null $liability_decided_at
 * @property int|null $finalized_by
 * @property CarbonImmutable|null $finalized_at
 * @property string|null $idempotency_key
 * @property-read BusinessAccount $businessAccount
 * @property-read Website|null $website
 * @property-read Order $order
 * @property-read OrderItem $orderItem
 * @property-read OrderReturn|null $orderReturn
 * @property-read OrderReturnItem|null $orderReturnItem
 * @property-read Warehouse|null $warehouse
 * @property-read Collection<int, OrderDamageStatusChange> $statusHistory
 * @property-read Collection<int, OrderDamageDisposition> $dispositions
 * @property-read Collection<int, DamageResponsibilityChange> $responsibilityChanges
 * @property-read Collection<int, DamageCharge> $charges
 * @property-read DamageDispatch|null $dispatch
 */
class OrderDamage extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * Knowing it would let somebody collide with a case deliberately.
     *
     * @var list<string>
     */
    protected $hidden = ['idempotency_key'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new RuntimeException('A damage case is never deleted; a rejected one is rejected, not removed.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => DamageSource::class,
            'status' => DamageStatus::class,
            'damage_condition' => DamageCondition::class,
            'cause' => DamageCause::class,
            'held_bucket' => StockBucket::class,
            'initiated_by_type' => ReturnInitiator::class,
            'responsible_party' => ReturnParty::class,
            'valuation_basis' => DamageValuationBasis::class,
            'parcel_expected' => 'boolean',
            'in_custody' => 'boolean',
            'evidence' => 'array',
            'valuation_snapshot' => 'array',
            'valuation_unit_price' => MoneyCast::class,
            'max_recoverable_value' => MoneyCast::class,
            'approved_loss_amount' => MoneyCast::class,
            'reported_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'inspected_at' => 'immutable_datetime',
            'liability_decided_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Damage;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
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
     * @return BelongsTo<OrderReturn, $this>
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class);
    }

    /**
     * @return BelongsTo<OrderReturnItem, $this>
     */
    public function orderReturnItem(): BelongsTo
    {
        return $this->belongsTo(OrderReturnItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<OrderDamageStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderDamageStatusChange::class)->orderBy('id');
    }

    /**
     * @return HasMany<OrderDamageDisposition, $this>
     */
    public function dispositions(): HasMany
    {
        return $this->hasMany(OrderDamageDisposition::class)->orderBy('id');
    }

    /**
     * The files of evidence on this case, oldest first (D35).
     *
     * @return HasMany<CaseEvidence, $this>
     */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(CaseEvidence::class, 'order_damage_id')->orderBy('id');
    }

    /**
     * @return HasMany<DamageResponsibilityChange, $this>
     */
    public function responsibilityChanges(): HasMany
    {
        return $this->hasMany(DamageResponsibilityChange::class)->orderBy('id');
    }

    /**
     * @return HasMany<DamageCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(DamageCharge::class)->orderBy('id');
    }

    /**
     * @return HasOne<DamageDispatch, $this>
     */
    public function dispatch(): HasOne
    {
        return $this->hasOne(DamageDispatch::class);
    }

    /**
     * Move the case along and record why, in one transaction.
     *
     * The caller has already decided the move is allowed and locked the case;
     * this refuses a move the map does not have and writes the move and its
     * history together.
     */
    public function moveTo(DamageStatus $to, StatusChange $change, OrderStatusChangeSource $source): OrderDamageStatusChange
    {
        /** @var OrderDamageStatusChange $entry */
        $entry = $this->transitionWithHistory($to, $change, ['source' => $source]);

        return $entry;
    }

    /**
     * Record the status a case was opened in.
     */
    public function recordOpening(StatusChange $change, OrderStatusChangeSource $source): OrderDamageStatusChange
    {
        /** @var OrderDamageStatusChange $entry */
        $entry = $this->recordStatusChange(null, $this->status, $change, ['source' => $source]);

        return $entry;
    }

    /**
     * The units inspection decided are damaged — the ones that stay held, are
     * never sellable, and may be sent to the Partner who bears the loss.
     */
    public function damagedQuantity(): int
    {
        return (int) $this->dispositions()->where('disposition', 'damaged')->sum('quantity');
    }

    /**
     * The number of times attribution has been decided or changed, less one: the
     * revision a charge is posted under.
     */
    public function responsibilityRevision(): int
    {
        return max(0, $this->responsibilityChanges()->count() - 1);
    }
}
