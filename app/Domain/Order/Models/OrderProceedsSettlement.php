<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether, and how much, a Non-Conditional reseller earns on one order line
 * (D-new). See the migration's own docblock for the two-independent-facts
 * shape this mirrors from `SupplierPayable`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $business_account_id
 * @property string $currency_code
 * @property Money $resale_amount
 * @property Money $recovered_amount
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $cod_collected_at
 * @property Money|null $cod_amount_collected
 * @property CarbonImmutable|null $eligible_at
 * @property Money|null $reseller_earning
 * @property bool $flagged_for_review
 * @property int|null $wallet_transaction_id
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read OrderItem $orderItem
 * @property-read BusinessAccount $businessAccount
 * @property-read WalletTransaction|null $walletTransaction
 */
class OrderProceedsSettlement extends Model
{
    use HasPublicId;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \RuntimeException('An order proceeds settlement cannot be deleted: it is the financial record of what this line owes.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resale_amount' => MoneyCast::class,
            'recovered_amount' => MoneyCast::class,
            'cod_amount_collected' => MoneyCast::class,
            'reseller_earning' => MoneyCast::class,
            'flagged_for_review' => 'boolean',
            'delivered_at' => 'immutable_datetime',
            'cod_collected_at' => 'immutable_datetime',
            'eligible_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
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
     * Both independent facts are in, and the wallet has been credited (or the
     * shortfall flagged instead of silently zeroed).
     */
    public function isSettled(): bool
    {
        return $this->eligible_at !== null;
    }
}
