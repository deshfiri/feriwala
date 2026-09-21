<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One effective-dated version of a Supplier offer's rates (D25, P13-15).
 *
 * Append-only, enforced by the database as well as by never issuing an
 * `update()` against it here: a rate change is always a new row with a new
 * `effective_from`, never an edit to a past one, so an order priced last
 * month keeps citing the rate that actually applied then.
 *
 * @property int $id
 * @property int $supplier_offer_id
 * @property Money $supplier_rate_minor
 * @property Money $platform_rate_minor
 * @property string $currency_code
 * @property CarbonImmutable $effective_from
 * @property int|null $changed_by
 * @property string $reason
 * @property CarbonImmutable $created_at
 * @property-read SupplierOffer $offer
 * @property-read User|null $changedBy
 */
class SupplierOfferPriceChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplier_rate_minor' => MoneyCast::class,
            'platform_rate_minor' => MoneyCast::class,
            'effective_from' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
