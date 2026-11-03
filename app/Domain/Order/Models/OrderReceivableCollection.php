<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Order\Data\ReceivableCollectionState;
use App\Domain\Order\Enums\ReceivableCollectionStatus;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Whether Banij's frozen receivable on a Partner-gateway order has been collected
 * from the Partner's Wallet (D36) — the one mutable record beside the append-only
 * receivable.
 *
 * What it was made from is frozen in the database: the receivable, order,
 * payment, amount, when the payment was verified and the deadline that follows
 * from it. Only the status and the facts that come with it ever change, and the
 * collection fact is written once. The status moves only through
 * {@see HasStateMachine::transitionTo()}.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_platform_receivable_id
 * @property int $order_id
 * @property int $business_account_id
 * @property int $payment_id
 * @property string $currency_code
 * @property Money $amount
 * @property ReceivableCollectionStatus $status
 * @property string|null $status_reason
 * @property CarbonImmutable $verified_at
 * @property int $deadline_hours
 * @property CarbonImmutable $deadline_at
 * @property CarbonImmutable|null $collected_at
 * @property int|null $wallet_transaction_id
 * @property CarbonImmutable|null $wallet_insufficient_at
 * @property CarbonImmutable|null $recovery_required_at
 * @property CarbonImmutable|null $deadline_expired_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read OrderPlatformReceivable $receivable
 * @property-read Payment $payment
 * @property-read BusinessAccount $businessAccount
 * @property-read WalletTransaction|null $walletTransaction
 */
class OrderReceivableCollection extends Model
{
    use HasPublicId, HasStateMachine;

    /** The order is waiting for the Partner's Wallet to cover Banij's share. */
    public const REASON_WALLET_INSUFFICIENT = 'partner_wallet_insufficient';

    /** The deadline passed without the Wallet covering it. */
    public const REASON_DEADLINE_EXPIRED = 'wallet_deadline_expired_refund_required';

    /** The stock could not be set aside again. */
    public const REASON_STOCK_RECOVERY = 'partner_stock_recovery_required';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'status' => ReceivableCollectionStatus::class,
            'deadline_hours' => 'integer',
            'verified_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'wallet_insufficient_at' => 'immutable_datetime',
            'recovery_required_at' => 'immutable_datetime',
            'deadline_expired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Waiting for the Wallet, oldest first — the order a top-up releases them in.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', array_map(
                static fn (ReceivableCollectionStatus $status): string => $status->value,
                array_filter(ReceivableCollectionStatus::cases(), static fn (ReceivableCollectionStatus $status): bool => $status->isActive()),
            ))
            ->orderBy('verified_at')
            ->orderBy('id');
    }

    public function isPastDeadline(?CarbonImmutable $now = null): bool
    {
        return ! $this->deadline_at->isAfter($now ?? CarbonImmutable::now());
    }

    public function state(): ReceivableCollectionState
    {
        return ReceivableCollectionState::of($this);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderPlatformReceivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(OrderPlatformReceivable::class, 'order_platform_receivable_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /**
     * @return HasMany<OrderHoldReservation, $this>
     */
    public function holdReservations(): HasMany
    {
        return $this->hasMany(OrderHoldReservation::class)->orderBy('order_item_id')->orderBy('sequence');
    }
}
