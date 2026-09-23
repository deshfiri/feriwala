<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Concerns\HasReference;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\References\ReferencePrefix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable entry in a Supplier's own ledger (D25, P13-23).
 *
 * Mirrors {@see LedgerEntry}: written once, corrected
 * only by a later entry pointing back at this one, never edited or deleted —
 * enforced here by the same `updating`/`deleting` guard, and by the database
 * trigger the migration adds on top (stricter than the original table, to
 * match this domain's own convention).
 *
 * `internal_note` is staff-only, for the same reason it is on the original.
 *
 * @property int $id
 * @property string $public_id
 * @property string $reference
 * @property int $supplier_wallet_id
 * @property int $supplier_id
 * @property SupplierLedgerEntryType $type
 * @property string $source
 * @property int|null $supplier_payable_id
 * @property int|null $supplier_payable_reversal_id
 * @property int|null $supplier_withdrawal_id
 * @property Money $debit_minor
 * @property Money $credit_minor
 * @property string $currency_code
 * @property Money $balance_before_minor
 * @property Money $balance_after_minor
 * @property Money $reserved_before_minor
 * @property Money $reserved_after_minor
 * @property Money $recovery_before_minor
 * @property Money $recovery_after_minor
 * @property string $description
 * @property string|null $internal_note
 * @property int|null $created_by
 * @property string|null $idempotency_key
 * @property int|null $corrects_ledger_entry_id
 * @property CarbonImmutable $created_at
 * @property-read SupplierWallet $wallet
 * @property-read Supplier $supplier
 */
class SupplierLedgerEntry extends Model
{
    use HasPublicId, HasReference;

    /** The columns a Supplier's own statement may ever see. */
    public const MEMBER_VISIBLE = [
        'id', 'public_id', 'reference', 'supplier_wallet_id', 'type', 'source',
        'debit_minor', 'credit_minor', 'currency_code', 'balance_after_minor',
        'description', 'created_at', 'corrects_ledger_entry_id',
    ];

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['internal_note'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'A Supplier ledger entry cannot be changed. Post a correcting entry instead.'
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'A Supplier ledger entry cannot be deleted. Post a reversing entry instead.'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SupplierLedgerEntryType::class,
            'debit_minor' => MoneyCast::class,
            'credit_minor' => MoneyCast::class,
            'balance_before_minor' => MoneyCast::class,
            'balance_after_minor' => MoneyCast::class,
            'reserved_before_minor' => MoneyCast::class,
            'reserved_after_minor' => MoneyCast::class,
            'recovery_before_minor' => MoneyCast::class,
            'recovery_after_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function referencePrefix(): ReferencePrefix
    {
        return ReferencePrefix::SupplierLedgerEntry;
    }

    /**
     * @return BelongsTo<SupplierWallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SupplierWallet::class, 'supplier_wallet_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierPayable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(SupplierPayable::class, 'supplier_payable_id');
    }

    /**
     * @return BelongsTo<SupplierPayableReversal, $this>
     */
    public function payableReversal(): BelongsTo
    {
        return $this->belongsTo(SupplierPayableReversal::class, 'supplier_payable_reversal_id');
    }

    /**
     * @return BelongsTo<SupplierWithdrawal, $this>
     */
    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(SupplierWithdrawal::class, 'supplier_withdrawal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_ledger_entry_id');
    }

    public function isCredit(): bool
    {
        return $this->credit_minor->isPositive();
    }

    /**
     * The amount, whichever side it landed on. Zero for a bucket-only entry
     * that moved neither (a pure recovery record, for instance).
     */
    public function amount(): Money
    {
        return $this->isCredit() ? $this->credit_minor : $this->debit_minor;
    }
}
