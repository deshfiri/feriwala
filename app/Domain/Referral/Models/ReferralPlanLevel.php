<?php

namespace App\Domain\Referral\Models;

use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\RewardType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rule for one level of one plan version (D24, P7-12).
 *
 * Written once, with its version; the database refuses any change.
 *
 * @property int $id
 * @property int $referral_plan_id
 * @property int $level
 * @property RewardType $reward_type
 * @property int|null $amount_minor
 * @property int|null $rate_bps
 * @property int|null $cap_minor
 * @property bool $is_enabled
 * @property list<int>|null $required_package_ids
 * @property int $min_active_direct_referrals
 * @property CarbonImmutable|null $created_at
 * @property-read ReferralPlan $plan
 */
class ReferralPlanLevel extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'reward_type' => RewardType::class,
            'amount_minor' => 'integer',
            'rate_bps' => 'integer',
            'cap_minor' => 'integer',
            'is_enabled' => 'boolean',
            'required_package_ids' => 'array',
            'min_active_direct_referrals' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ReferralPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ReferralPlan::class, 'referral_plan_id');
    }

    public function rule(): RewardRule
    {
        return new RewardRule($this->reward_type, $this->amount_minor, $this->rate_bps, $this->cap_minor);
    }

    /**
     * @return list<int>
     */
    public function requiredPackageIds(): array
    {
        return array_map('intval', $this->required_package_ids ?? []);
    }
}
