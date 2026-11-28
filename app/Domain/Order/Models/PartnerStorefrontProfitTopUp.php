<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * More profit paid on an order after its first credit: the difference when the
 * profit is worth more than was credited — as when the formula was restated on
 * 2026-11-28. Posted with its own Wallet key; append-only by trigger.
 *
 * @property int $id
 * @property string $public_id
 * @property int $partner_storefront_profit_id
 * @property string $currency_code
 * @property Money $amount
 * @property string $idempotency_key
 * @property int $wallet_transaction_id
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property-read PartnerStorefrontProfit $profit
 */
class PartnerStorefrontProfitTopUp extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PartnerStorefrontProfit, $this>
     */
    public function profit(): BelongsTo
    {
        return $this->belongsTo(PartnerStorefrontProfit::class, 'partner_storefront_profit_id');
    }
}
