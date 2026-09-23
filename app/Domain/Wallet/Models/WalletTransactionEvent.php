<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Enums\WalletTransactionStatus;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One state a wallet transaction passed through, kept for good (§23.3).
 *
 * The lifecycle record beside the ledger, not a second ledger. A
 * {@see LedgerEntry} exists when value entered or left the wallet; one of these
 * exists every time a transaction changed state, including the changes that move
 * money only between buckets — a reservation placed, released, or captured.
 *
 * Written once and never touched again, enforced by a database trigger as well
 * as by this model. `wallet_transactions.status` says where a claim is now; this
 * says how it got there, and cannot be quietly rewritten afterwards.
 *
 * @property int $id
 * @property int $wallet_transaction_id
 * @property int $wallet_id
 * @property int $business_account_id
 * @property WalletTransactionStatus|null $from_status
 * @property WalletTransactionStatus $to_status
 * @property string|null $bucket
 * @property Money $amount
 * @property string $currency_code
 * @property Money|null $bucket_before
 * @property Money|null $bucket_after
 * @property Money $total_before
 * @property Money $total_after
 * @property string $source
 * @property int|null $actor_id
 * @property string|null $reason
 * @property string|null $idempotency_key
 * @property int|null $ledger_entry_id
 * @property CarbonImmutable $occurred_at
 */
class WalletTransactionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'A wallet transaction event cannot be changed. Append the next state instead (§23.3).'
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'A wallet transaction event cannot be deleted. It is the record of what happened (§23.3).'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => WalletTransactionStatus::class,
            'to_status' => WalletTransactionStatus::class,
            'amount' => MoneyCast::class,
            'bucket_before' => MoneyCast::class,
            'bucket_after' => MoneyCast::class,
            'total_before' => MoneyCast::class,
            'total_after' => MoneyCast::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WalletTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<LedgerEntry, $this>
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }

    /**
     * Whether this event moved money between buckets rather than in or out.
     */
    public function movedABucket(): bool
    {
        return $this->bucket !== null;
    }
}
