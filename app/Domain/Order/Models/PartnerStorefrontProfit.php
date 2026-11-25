<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Partner's profit on one Storefront order, and its way to their Wallet.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $business_account_id
 * @property string $currency_code
 * @property StorefrontProfitStatus $status
 * @property string|null $status_reason
 * @property CollectionChannel|null $collection_channel
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $collected_at
 * @property Money|null $collected_amount
 * @property int|null $cod_confirmed_by
 * @property CarbonImmutable|null $release_at
 * @property Money|null $selling_amount
 * @property Money|null $cost_amount
 * @property Money|null $profit_amount
 * @property Money $released_amount
 * @property Money $reversed_amount
 * @property CarbonImmutable|null $released_at
 * @property int|null $wallet_transaction_id
 * @property Money $legacy_credited_amount
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property string|null $failure_message
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Order $order
 * @property-read BusinessAccount $businessAccount
 * @property-read WalletTransaction|null $walletTransaction
 * @property-read Collection<int, PartnerStorefrontProfitLine> $lines
 * @property-read Collection<int, PartnerStorefrontProfitReversal> $reversals
 */
class PartnerStorefrontProfit extends Model
{
    use HasPublicId, HasStateMachine;

    /** The hold between the authoritative Delivered time and the credit. */
    public const HOLD_HOURS = 24;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StorefrontProfitStatus::class,
            'collection_channel' => CollectionChannel::class,
            'delivered_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
            'collected_amount' => MoneyCast::class,
            'release_at' => 'immutable_datetime',
            'selling_amount' => MoneyCast::class,
            'cost_amount' => MoneyCast::class,
            'profit_amount' => MoneyCast::class,
            'released_amount' => MoneyCast::class,
            'reversed_amount' => MoneyCast::class,
            'released_at' => 'immutable_datetime',
            'legacy_credited_amount' => MoneyCast::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function stateAttribute(): string
    {
        return 'status';
    }

    /** The Wallet key that makes the credit happen at most once. */
    public function creditKey(): string
    {
        return 'partner-storefront-profit:'.$this->order->public_id;
    }

    /** What has reached the Wallet and stayed there. */
    public function netCredited(): Money
    {
        return $this->released_amount->minus($this->reversed_amount);
    }

    /**
     * Profits whose hold has ended and are waiting for the sweep.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDue(Builder $query, CarbonImmutable $now): Builder
    {
        return $query
            ->where('status', StorefrontProfitStatus::Holding->value)
            ->whereNotNull('release_at')
            ->where('release_at', '<=', $now);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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
     * @return HasMany<PartnerStorefrontProfitLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PartnerStorefrontProfitLine::class);
    }

    /**
     * @return HasMany<PartnerStorefrontProfitReversal, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(PartnerStorefrontProfitReversal::class);
    }
}
