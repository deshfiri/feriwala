<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profit taken back after it was credited: posted as a compensating debit, or
 * owed until the Wallet can carry it — never into a negative balance.
 *
 * @property int $id
 * @property string $public_id
 * @property int $partner_storefront_profit_id
 * @property string $currency_code
 * @property Money $amount
 * @property string $status `owed` or `posted`
 * @property string $cause `return`, `cancellation` or `correction`
 * @property string $idempotency_key
 * @property int|null $wallet_transaction_id
 * @property string|null $reason
 * @property CarbonImmutable|null $posted_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read PartnerStorefrontProfit $profit
 */
class PartnerStorefrontProfitReversal extends Model
{
    use HasPublicId;

    public const STATUS_OWED = 'owed';

    public const STATUS_POSTED = 'posted';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'posted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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
