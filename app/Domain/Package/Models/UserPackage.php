<?php

namespace App\Domain\Package\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Package\Enums\UserPackageStatus;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's subscription to a package (§8.2).
 *
 * @property UserPackageStatus $status
 * @property Money|null $paid_fee_minor
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $grace_ends_at
 */
class UserPackage extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => UserPackageStatus::class,
            'paid_fee_minor' => MoneyCast::class,
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Whether this subscription currently entitles the account to its features.
     *
     * A package inside its grace period still entitles: §8.4 puts a website into
     * a grace period rather than switching it off the moment the term ends, and
     * cutting entitlements at expiry would contradict that.
     */
    public function entitlesNow(): bool
    {
        if (! $this->status->entitles()) {
            return false;
        }

        $now = now();

        if ($this->started_at !== null && $now->lt($this->started_at)) {
            return false;
        }

        if ($this->expires_at === null) {
            return true;
        }

        $effectiveEnd = $this->grace_ends_at ?? $this->expires_at;

        return $now->lt($effectiveEnd);
    }

    /**
     * Past its term but still inside the grace period (§8.4).
     */
    public function isInGracePeriod(): bool
    {
        if ($this->expires_at === null || $this->grace_ends_at === null) {
            return false;
        }

        $now = now();

        return $now->gte($this->expires_at) && $now->lt($this->grace_ends_at);
    }

    /**
     * @param  Builder<UserPackage>  $query
     * @return Builder<UserPackage>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserPackageStatus::Active);
    }
}
