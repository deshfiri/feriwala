<?php

namespace App\Domain\Referral\Models;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Enums\ReferralAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's direct referrer (§25.1, D24, P7-11).
 *
 * At most one per account, never itself and never one of its own descendants —
 * the database refuses all three. Once a qualifying event has used it,
 * `locked_at` is set and it can no longer change or go away.
 *
 * @property int $id
 * @property int $referred_account_id
 * @property int $referrer_account_id
 * @property string|null $referral_code
 * @property ReferralAttachment $attached_via
 * @property int|null $attached_by
 * @property string|null $reason
 * @property CarbonImmutable|null $locked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BusinessAccount $referred
 * @property-read BusinessAccount $referrer
 * @property-read User|null $attachedBy
 */
class AccountReferral extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attached_via' => ReferralAttachment::class,
            'locked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'referred_account_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'referrer_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function attachedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attached_by');
    }
}
