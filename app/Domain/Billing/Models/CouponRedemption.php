<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\RedemptionStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a coupon, by one account, on one payment (§9).
 *
 * The amount is snapshotted rather than recomputed. The coupon can be edited
 * afterwards, and this has to go on reconciling with the payment allocation it
 * produced — the same reason an invoice copies its lines.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $business_account_id
 * @property int|null $payment_id
 * @property RedemptionStatus $status
 * @property Money $amount_minor
 * @property CarbonImmutable|null $reserved_at
 * @property CarbonImmutable|null $redeemed_at
 * @property CarbonImmutable|null $released_at
 * @property-read Coupon $coupon
 */
class CouponRedemption extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RedemptionStatus::class,
            'amount_minor' => MoneyCast::class,
            'reserved_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
