<?php

namespace App\Domain\Referral\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Referral\Enums\CommissionSkipReason;
use App\Domain\Referral\Enums\CommissionStatus;
use App\Domain\Referral\Enums\ReversalCause;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One level of one qualifying event, as it was decided (D24, P7-42, P7-43).
 *
 * The outbox row: written in the qualifying event's own transaction with a
 * snapshot of the rule that was applied, and paid to the wallet later with an
 * idempotency key of its own. Its terms never change; only its progress does,
 * through {@see CommissionStatus}.
 *
 * Level 0 is the new account's joining reward (D14).
 *
 * @property int $id
 * @property string $public_id
 * @property int $referral_qualifying_event_id
 * @property int $referral_plan_id
 * @property int $beneficiary_account_id
 * @property int $source_account_id
 * @property int $level
 * @property string $kind
 * @property array{type: string, amount: array<string, mixed>|null, rate_bps: int|null, cap: array<string, mixed>|null, enabled?: bool, required_package_ids?: list<int>, min_active_direct_referrals?: int} $rule_snapshot
 * @property Money $commission_base
 * @property Money $amount
 * @property string $currency_code
 * @property bool $capped
 * @property CommissionStatus $status
 * @property CommissionSkipReason|null $skip_reason
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $paid_at
 * @property int|null $wallet_transaction_id
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversal_reason
 * @property ReversalCause|null $reversal_cause
 * @property int|null $reversed_by
 * @property int|null $reversal_wallet_transaction_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ReferralQualifyingEvent $event
 * @property-read ReferralPlan $plan
 * @property-read BusinessAccount $beneficiary
 * @property-read BusinessAccount $sourceAccount
 * @property-read WalletTransaction|null $walletTransaction
 * @property-read User|null $reversedBy
 */
class ReferralCommission extends Model
{
    use HasPublicId, HasStateMachine;

    public const KIND_LEVEL = 'level';

    public const KIND_JOINING = 'joining';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'rule_snapshot' => 'array',
            'commission_base' => MoneyCast::class,
            'amount' => MoneyCast::class,
            'capped' => 'boolean',
            'status' => CommissionStatus::class,
            'skip_reason' => CommissionSkipReason::class,
            'reversal_cause' => ReversalCause::class,
            'available_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isJoiningReward(): bool
    {
        return $this->kind === self::KIND_JOINING;
    }

    /**
     * The ledger type this commission is paid as (D24).
     */
    public function creditType(): LedgerTransactionType
    {
        return $this->isJoiningReward()
            ? LedgerTransactionType::JoiningRewardCredit
            : LedgerTransactionType::ReferralRewardCredit;
    }

    public function idempotencyKey(): string
    {
        return 'referral-commission:'.$this->public_id;
    }

    public function reversalKey(): string
    {
        return 'referral-commission-reversal:'.$this->public_id;
    }

    /**
     * @param  Builder<ReferralCommission>  $query
     */
    public function scopeDue(Builder $query, CarbonImmutable $at): void
    {
        $query->where('status', CommissionStatus::Pending->value)->where('available_at', '<=', $at);
    }

    /**
     * @return BelongsTo<ReferralQualifyingEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ReferralQualifyingEvent::class, 'referral_qualifying_event_id');
    }

    /**
     * @return BelongsTo<ReferralPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ReferralPlan::class, 'referral_plan_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'beneficiary_account_id');
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'source_account_id');
    }

    /**
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
