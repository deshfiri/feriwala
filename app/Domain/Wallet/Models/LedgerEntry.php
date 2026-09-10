<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Models\Payment;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable entry in the financial ledger (§23.2).
 *
 * Written once, never changed, never deleted — the same rule the audit log
 * follows, for the same reason: a record that can be edited afterwards is not
 * evidence of anything. A mistake is answered by a **new** entry pointing at the
 * one it corrects (§23.2's adjustment, reversal and corrective entries), so the
 * wrong figure and the putting-right of it are both visible.
 *
 * Debit and credit are both stored **positive** and told apart by which column
 * they land in. Summing "everything debited in March" then needs no sign
 * handling, and a misplaced minus cannot quietly become a credit.
 *
 * `internal_note` is staff-only (§23.2). Nothing that renders an account
 * member's statement may select it.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $wallet_id
 * @property int $business_account_id
 * @property int|null $user_id
 * @property LedgerTransactionType $type
 * @property string $source
 * @property int|null $payment_id
 * @property int|null $user_package_id
 * @property Money $debit_minor
 * @property Money $credit_minor
 * @property string $currency_code
 * @property Money $balance_before_minor
 * @property Money $balance_after_minor
 * @property Money $pending_minor
 * @property Money $reserved_minor
 * @property Money $available_minor
 * @property Money $hold_minor
 * @property WalletTransactionStatus $status
 * @property int|null $wallet_transaction_id
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property string $description
 * @property string|null $internal_note
 * @property string|null $idempotency_key
 * @property int|null $corrects_ledger_entry_id
 * @property CarbonImmutable $created_at
 * @property-read Wallet $wallet
 */
class LedgerEntry extends Model
{
    use HasPublicId, HasReference;

    /** The columns an account member's own statement may ever see. */
    public const MEMBER_VISIBLE = [
        'id', 'public_id', 'reference', 'wallet_id', 'business_account_id',
        'type', 'source', 'debit_minor', 'credit_minor', 'currency_code',
        'balance_after_minor', 'status', 'description', 'created_at',
        'corrects_ledger_entry_id',
    ];

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Never serialised, whatever a caller forgets. §23.2's internal note is for
     * staff, and a statement that leaked one would be leaking a reviewer's
     * private words to the person they are about.
     *
     * @var list<string>
     */
    protected $hidden = ['internal_note'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'A ledger entry cannot be changed. Post a correcting entry instead (§23.2).'
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'A ledger entry cannot be deleted. Post a reversing entry instead (§23.2).'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'status' => WalletTransactionStatus::class,
            'debit_minor' => MoneyCast::class,
            'credit_minor' => MoneyCast::class,
            'balance_before_minor' => MoneyCast::class,
            'balance_after_minor' => MoneyCast::class,
            'pending_minor' => MoneyCast::class,
            'reserved_minor' => MoneyCast::class,
            'available_minor' => MoneyCast::class,
            'hold_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The entry this one puts right, if it is a correction (§23.2).
     *
     * @return BelongsTo<self, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_ledger_entry_id');
    }

    /**
     * Whether money moved into the wallet or out of it.
     */
    public function isCredit(): bool
    {
        return $this->credit_minor->isPositive();
    }

    /**
     * The signed effect on the balance, for a running total.
     */
    public function signedAmount(): Money
    {
        return $this->isCredit()
            ? $this->credit_minor
            : $this->debit_minor->negated();
    }

    /**
     * The amount, whichever side it landed on.
     */
    public function amount(): Money
    {
        return $this->isCredit() ? $this->credit_minor : $this->debit_minor;
    }

    /**
     * Whether this entry's own arithmetic holds.
     *
     * Read by the P2-9 integrity sweep. An entry whose ends do not match its
     * own movement is corrupt regardless of what the wallet says.
     */
    public function balances(): bool
    {
        return $this->balance_before_minor
            ->plus($this->signedAmount())
            ->equals($this->balance_after_minor);
    }
}
