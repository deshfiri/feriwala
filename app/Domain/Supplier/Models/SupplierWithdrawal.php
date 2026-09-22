<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Concerns\RecordsStatusHistory;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Supplier's request to withdraw money from their wallet (D25, P13-24).
 *
 * Its identity, amount, currency, payout snapshot and idempotency key are
 * locked by trigger from the moment the row exists — the migration's own
 * doc-comment explains why. Only the workflow columns move, and only through
 * {@see HasStateMachine::transitionTo()} via {@see RecordsStatusHistory}.
 *
 * `payout_snapshot` is frozen at request time from the
 * {@see SupplierPayoutMethod} it names, so an edit or an archive of that
 * method afterwards can never change what this withdrawal says it was paid
 * to.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $supplier_id
 * @property int $supplier_wallet_id
 * @property int $supplier_payout_method_id
 * @property array<string, mixed> $payout_snapshot
 * @property Money $amount_minor
 * @property string $currency_code
 * @property SupplierWithdrawalStatus $status
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
 * @property-read Supplier $supplier
 * @property-read SupplierWallet $wallet
 * @property-read SupplierPayoutMethod $payoutMethod
 * @property-read Collection<int, SupplierWithdrawalStatusChange> $statusHistory
 */
class SupplierWithdrawal extends Model
{
    use HasPublicId, HasReference, HasStateMachine, RecordsStatusHistory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierWithdrawalStatus::class,
            'payout_snapshot' => 'array',
            'amount_minor' => MoneyCast::class,
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SupplierWallet::class, 'supplier_wallet_id');
    }

    /**
     * @return BelongsTo<SupplierPayoutMethod, $this>
     */
    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(SupplierPayoutMethod::class, 'supplier_payout_method_id');
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
     * @return HasMany<SupplierWithdrawalStatusChange, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupplierWithdrawalStatusChange::class, 'supplier_withdrawal_id')->orderBy('id');
    }
}
