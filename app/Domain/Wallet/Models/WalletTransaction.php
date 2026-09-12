<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Concerns\HasStateMachine;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Wallet\Enums\LedgerDirection;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One wallet movement, over its lifetime (§23.3).
 *
 * The mutable half. §23.3 gives a transaction twelve statuses to move through
 * and §23.2 makes a ledger entry immutable, so the two cannot be one row: this
 * is what happens over time, and a {@see LedgerEntry} is the frozen record of
 * what it did to the balance.
 *
 * Status moves only through `transitionTo()`, so an illegal move — reviving a
 * failed transaction, un-paying money — is refused rather than written.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $wallet_id
 * @property int $business_account_id
 * @property int|null $user_id
 * @property LedgerTransactionType $type
 * @property LedgerDirection $direction
 * @property string $source
 * @property Money $amount_minor
 * @property string $currency_code
 * @property WalletTransactionStatus $status
 * @property int|null $payment_id
 * @property string $description
 * @property string|null $internal_note
 * @property string|null $reason
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $created_at
 * @property-read Wallet $wallet
 */
class WalletTransaction extends Model
{
    use HasPublicId, HasReference, HasStateMachine;

    protected $guarded = [];

    /**
     * Staff-only, like the ledger's (§23.2).
     *
     * @var list<string>
     */
    protected $hidden = ['internal_note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'direction' => LedgerDirection::class,
            'status' => WalletTransactionStatus::class,
            'amount_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::Transaction;
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
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * What this transaction actually did to the balance, if anything yet.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderBy('id');
    }

    /**
     * Every state this transaction has passed through (§23.3).
     *
     * The audit trail for movement that never reaches the ledger, because it
     * never crossed the wallet's edge: a reservation placed, released or
     * captured. `status` says where it is now; these say how it got there, and
     * unlike `status` they cannot be overwritten.
     *
     * @return HasMany<WalletTransactionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(WalletTransactionEvent::class)->orderBy('id');
    }

    /**
     * Whether the money has actually reached the wallet's spendable balance.
     */
    public function isRealised(): bool
    {
        return $this->status->isRealised();
    }

    public function isCredit(): bool
    {
        return $this->direction->isCredit();
    }
}
