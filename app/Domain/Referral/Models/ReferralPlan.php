<?php

namespace App\Domain\Referral\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Package\Models\Package;
use App\Domain\Referral\Data\RewardRule;
use App\Domain\Referral\Enums\CommissionBase;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Domain\Referral\Enums\RewardType;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a multi-level referral plan (§25.4, §25.4.1, D24, P7-12).
 *
 * Opened and closed, never edited — the database refuses a change to anything
 * but its closing. A version with no package is the global default; one
 * naming a package replaces it, whole, for events whose account holds that
 * package.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $package_id
 * @property ReferralTrigger $trigger_event
 * @property CommissionBase $commission_base
 * @property int $max_depth
 * @property string $currency_code
 * @property RewardType|null $joining_reward_type
 * @property Money|null $joining_reward_amount
 * @property int|null $joining_reward_rate_bps
 * @property Money|null $joining_reward_cap
 * @property int $holding_days
 * @property Money $minimum_qualifying_payment
 * @property bool $qualifies_suspended
 * @property bool $qualifies_restricted
 * @property bool $qualifies_package_lapsed
 * @property bool $qualifies_not_active
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $reason
 * @property int $opened_by
 * @property int|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property string|null $close_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Package|null $package
 * @property-read Collection<int, ReferralPlanLevel> $levels
 * @property-read User $openedBy
 * @property-read User|null $closedBy
 */
class ReferralPlan extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_event' => ReferralTrigger::class,
            'commission_base' => CommissionBase::class,
            'joining_reward_type' => RewardType::class,
            'max_depth' => 'integer',
            'holding_days' => 'integer',
            'minimum_qualifying_payment' => MoneyCast::class,
            'joining_reward_amount' => MoneyCast::class,
            'joining_reward_rate_bps' => 'integer',
            'joining_reward_cap' => MoneyCast::class,
            'qualifies_suspended' => 'boolean',
            'qualifies_restricted' => 'boolean',
            'qualifies_package_lapsed' => 'boolean',
            'qualifies_not_active' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
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
     * @return HasMany<ReferralPlanLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(ReferralPlanLevel::class)->orderBy('level');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Versions in force at a moment.
     *
     * @param  Builder<ReferralPlan>  $query
     */
    public function scopeInForceAt(Builder $query, CarbonImmutable $at): void
    {
        $query->where('effective_from', '<=', $at)
            ->where(fn (Builder $open) => $open->whereNull('effective_to')->orWhere('effective_to', '>', $at));
    }

    public function isInForceAt(CarbonImmutable $at): bool
    {
        return $this->effective_from->lessThanOrEqualTo($at)
            && ($this->effective_to === null || $this->effective_to->greaterThan($at));
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function joiningRule(): ?RewardRule
    {
        return $this->joining_reward_type === null ? null : new RewardRule(
            $this->joining_reward_type,
            $this->joining_reward_amount,
            $this->joining_reward_rate_bps,
            $this->joining_reward_cap,
        );
    }

    /**
     * Whether a beneficiary in this state may still be paid under this version.
     *
     * Closed never qualifies; active always does; the rest as the version says.
     */
    public function qualifiesStatus(AccountStatus $status): bool
    {
        return match (true) {
            $status === AccountStatus::Closed => false,
            $status === AccountStatus::Active => true,
            $status === AccountStatus::Suspended => $this->qualifies_suspended,
            in_array($status, [
                AccountStatus::TemporarilyRestricted, AccountStatus::TemporarilyDisabled,
                AccountStatus::LowWalletBalance, AccountStatus::WalletTopupRequired,
            ], true) => $this->qualifies_restricted,
            in_array($status, [AccountStatus::PackageRenewalDue, AccountStatus::PackageExpired], true) => $this->qualifies_package_lapsed,
            default => $this->qualifies_not_active,
        };
    }
}
