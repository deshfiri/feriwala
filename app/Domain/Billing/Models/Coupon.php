<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Billing\Enums\CouponScope;
use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Enums\RedemptionStatus;
use App\Domain\Package\Models\Package;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A coupon or promotional discount (§9).
 *
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property string $name
 * @property DiscountType $discount_type
 * @property int $value
 * @property string $currency_code
 * @property CouponScope $applies_to
 * @property int|null $package_id
 * @property Money|null $minimum_spend_minor
 * @property Money|null $maximum_discount_minor
 * @property int|null $usage_limit
 * @property int|null $per_account_limit
 * @property int $redeemed_count
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 * @property-read Package|null $package
 */
class Coupon extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'applies_to' => CouponScope::class,
            'value' => 'integer',
            'minimum_spend_minor' => MoneyCast::class,
            'maximum_discount_minor' => MoneyCast::class,
            'usage_limit' => 'integer',
            'per_account_limit' => 'integer',
            'redeemed_count' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Find a coupon by the code somebody typed.
     *
     * Case-insensitive and trimmed, because a code read off a poster arrives
     * with whatever capitals and spaces the reader used.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithCode(Builder $query, string $code): void
    {
        $query->whereRaw('lower(code) = ?', [mb_strtolower(trim($code))]);
    }

    /**
     * Whether the coupon's own window is open.
     *
     * Only the window and the switch. Whether *this account* may use it on
     * *this quote* is a longer question, and it lives in the validator where
     * the refusal can say which part failed.
     */
    public function isOpen(?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();

        return $this->is_active
            && $this->effective_from->lte($at)
            && ($this->effective_until === null || $this->effective_until->gt($at));
    }

    /**
     * Slots left against the overall limit, or null when unlimited.
     */
    public function remainingUses(): ?int
    {
        return $this->usage_limit === null
            ? null
            : max($this->usage_limit - $this->redeemed_count, 0);
    }

    /**
     * How many times this account already holds or has used it.
     */
    public function usesBy(int $businessAccountId): int
    {
        return $this->redemptions()
            ->where('business_account_id', $businessAccountId)
            ->whereIn('status', RedemptionStatus::counting())
            ->count();
    }

    /**
     * What this coupon takes off a given base.
     *
     * Percentages are worked out in minor units and **rounded down**, so a
     * discount is never a unit more generous than the rate says. A cap applies
     * afterwards, and the result never exceeds the base — a coupon cannot turn
     * a sale into a payout.
     */
    public function discountOn(Money $base): Money
    {
        $currency = $base->currency;

        $raw = $this->discount_type === DiscountType::Percentage
            ? Money::of(intdiv($base->minorUnits * $this->value, 10000), $currency)
            : Money::of($this->value, $currency);

        $cap = $this->maximum_discount_minor;

        if ($cap !== null && $cap->isPositive() && $raw->greaterThan($cap)) {
            $raw = Money::of($cap->minorUnits, $currency);
        }

        return $raw->greaterThan($base) ? $base : $raw;
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency_code);
    }
}
