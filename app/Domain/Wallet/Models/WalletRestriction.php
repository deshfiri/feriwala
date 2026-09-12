<?php

namespace App\Domain\Wallet\Models;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Enums\WalletRestrictionStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing taken away because the balance fell short (§24.3).
 *
 * Mutable in exactly two columns — when it was lifted, and why — because a
 * restriction is a state that ends, not an event that happened. What happened is
 * in the audit log, immutably, at both ends.
 *
 * `cause` is what keeps restoration honest: only rows this rule placed are ever
 * lifted by it. An account restricted for a failed review does not get its panel
 * back by paying a deposit.
 *
 * @property int $id
 * @property int $wallet_id
 * @property int $business_account_id
 * @property WalletRestrictionStage $stage
 * @property string $cause
 * @property string|null $previous_account_status
 * @property string|null $applied_account_status
 * @property string|null $reason
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $lifted_at
 * @property string|null $lifted_reason
 */
class WalletRestriction extends Model
{
    /** Everything this module places, and the only thing it lifts. */
    public const LOW_BALANCE = 'low_balance';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => WalletRestrictionStage::class,
            'started_at' => 'immutable_datetime',
            'lifted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * Restrictions still in force.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStanding(Builder $query): void
    {
        $query->whereNull('lifted_at');
    }

    /**
     * Restrictions this module placed, and therefore may lift.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFromLowBalance(Builder $query): void
    {
        $query->where('cause', self::LOW_BALANCE);
    }

    public function isStanding(): bool
    {
        return $this->lifted_at === null;
    }
}
