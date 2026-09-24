<?php

namespace App\Domain\Referral\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Referral\Enums\ReferralTrigger;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something that happened which a referral plan pays for (D24, P7-42).
 *
 * One per trigger and subject, ever — one activation per account — which is
 * what makes a retry or a duplicate callback pay nothing twice. It keeps the
 * plan version it was decided under, the commission base, and the chain as it
 * stood, level by level.
 *
 * @property int $id
 * @property string $public_id
 * @property ReferralTrigger $trigger_event
 * @property int $source_account_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $payment_id
 * @property int $referral_plan_id
 * @property string $currency_code
 * @property Money $commission_base
 * @property list<array{level: int, account: string|null, outcome: string}> $chain
 * @property string $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversal_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read BusinessAccount $sourceAccount
 * @property-read Payment|null $payment
 * @property-read ReferralPlan $plan
 * @property-read Collection<int, ReferralCommission> $commissions
 */
class ReferralQualifyingEvent extends Model
{
    use HasPublicId;

    public const SUBJECT_ACCOUNT = 'business_account';

    public const RECORDED = 'recorded';

    public const REVERSED = 'reversed';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_event' => ReferralTrigger::class,
            'commission_base' => MoneyCast::class,
            'chain' => 'array',
            'occurred_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'source_account_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<ReferralPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ReferralPlan::class, 'referral_plan_id');
    }

    /**
     * @return HasMany<ReferralCommission, $this>
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class)->orderBy('level');
    }
}
