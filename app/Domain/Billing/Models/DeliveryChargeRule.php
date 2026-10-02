<?php

namespace App\Domain\Billing\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Courier\Models\CourierProvider;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated delivery-charge rule, by chargeable weight and an optional area
 * or courier scope (beta-critical batch, Commit 2).
 *
 * Never edited into a new price -- see the migration's own docblock, the
 * same `fee_rules` convention this mirrors. {@see
 * \App\Domain\Billing\Actions\ManageDeliveryChargeRules} is the one place a
 * rule is created or closed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $weight_from_grams
 * @property int|null $weight_to_grams
 * @property Money $base_charge
 * @property Money|null $per_kg_charge
 * @property string|null $area
 * @property int|null $courier_provider_id
 * @property int $priority
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property bool $is_active
 * @property string|null $note
 * @property-read CourierProvider|null $courierProvider
 * @property-read User|null $createdBy
 */
class DeliveryChargeRule extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight_from_grams' => 'integer',
            'weight_to_grams' => 'integer',
            'base_charge' => MoneyCast::class,
            'per_kg_charge' => MoneyCast::class,
            'priority' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CourierProvider, $this>
     */
    public function courierProvider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Rules in force at a moment -- inactive ones excluded here rather than
     * filtered by callers, so nothing can accidentally price against a rule
     * somebody switched off (mirrors {@see FeeRule::scopeEffectiveAt()}).
     *
     * @param  Builder<self>  $query
     */
    public function scopeEffectiveAt(Builder $query, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        $query->where('is_active', true)
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }

    /**
     * Whether a chargeable weight falls inside this rule's band.
     */
    public function coversWeight(int $chargeableWeightGrams): bool
    {
        return $chargeableWeightGrams >= $this->weight_from_grams
            && ($this->weight_to_grams === null || $chargeableWeightGrams < $this->weight_to_grams);
    }
}
