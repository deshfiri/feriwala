<?php

namespace App\Domain\Order\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Website\Models\Website;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What Banij is owed on one Store Order, frozen when it was placed (D36).
 *
 * Append-only in the database: written once, never edited, never deleted. The
 * total is the sum of its {@see OrderPlatformReceivableLine}s, which hold every
 * component. Whether it was collected is another record's business.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property int|null $payment_id
 * @property int $business_account_id
 * @property int $website_id
 * @property string $currency_code
 * @property Money $total
 * @property string $calculation_version
 * @property string|null $account_type_at_placement the account's type when the decision was made; null on a receivable frozen before it was recorded
 * @property string|null $eligibility_version
 * @property string|null $gateway_ownership
 * @property CarbonImmutable $frozen_at
 * @property CarbonImmutable $created_at
 * @property-read Order $order
 * @property-read Payment|null $payment
 * @property-read BusinessAccount $businessAccount
 * @property-read Website $website
 * @property-read Collection<int, OrderPlatformReceivableLine> $lines
 */
class OrderPlatformReceivable extends Model
{
    use HasPublicId;

    /** The formula these figures were worked out with. */
    public const CALCULATION_VERSION = 'v1';

    /** The rule that decided this order may be paid to the Partner's own gateway. */
    public const ELIGIBILITY_VERSION = 'd36.v1';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => MoneyCast::class,
            'frozen_at' => 'immutable_datetime',
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
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Whether it has been collected from the Partner's Wallet — the one record about it that moves.
     *
     * @return HasOne<OrderReceivableCollection, $this>
     */
    public function collection(): HasOne
    {
        return $this->hasOne(OrderReceivableCollection::class, 'order_platform_receivable_id');
    }

    /**
     * @return HasMany<OrderPlatformReceivableLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderPlatformReceivableLine::class)->orderBy('id');
    }
}
