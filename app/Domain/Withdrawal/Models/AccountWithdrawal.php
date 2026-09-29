<?php

namespace App\Domain\Withdrawal\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Client/Partner `BusinessAccount`'s request to withdraw money from its
 * wallet (§27, D25 — mirrors `SupplierWithdrawal` for a different owner and
 * a different underlying reservation primitive; see the migration's own
 * docblock for why `wallet_transaction_id` exists here and has no Supplier
 * equivalent).
 *
 * Its identity, amount, currency, payout snapshot, wallet transaction and
 * idempotency key are locked by trigger from the moment the row exists.
 * Only the workflow columns move, and only through
 * {@see HasStateMachine::transitionTo()} via {@see RecordsStatusHistory}.
 *
 * `payout_snapshot` is frozen at request time from the {@see PayoutMethod}
 * it names, so an edit or an archive of that method afterwards can never
 * change what this withdrawal says it was paid to.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $business_account_id
 * @property int $wallet_id
 * @property int $payout_method_id
 * @property int $wallet_transaction_id
 * @property array<string, mixed> $payout_snapshot
 * @property Money $amount
 * @property string $currency_code
 * @property AccountWithdrawalStatus $status
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $processed_by
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $paid_at
 * @property string|null $external_reference
 * @property string|null $failure_reason
 * @property string $idempotency_key
 * @property CarbonImmutable $created_at
 * @property-read BusinessAccount $businessAccount
 * @property-read Wallet $wallet
 * @property-read PayoutMethod $payoutMethod
 * @property-read WalletTransaction $walletTransaction
 * @property-read Collection<int, AccountWithdrawalStatusChange> $statusHistory
 */
class AccountWithdrawal extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccountWithdrawalStatus::class,
            'payout_snapshot' => 'array',
            'amount' => MoneyCast::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Withdrawal;
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<PayoutMethod, $this>
     */
    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(PayoutMethod::class);
    }

    /**
     * The wallet's reservation claim this withdrawal names — `Pending` while
     * reserved, `Settled` once paid, `Cancelled` once released.
     *
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * @return HasMany<AccountWithdrawalStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(AccountWithdrawalStatusChange::class, 'account_withdrawal_id')->orderBy('id');
    }
}
