<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Supplier\Actions\SupplierWalletService;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Supplier's own wallet, in one currency (D25, P13-23).
 *
 * Deliberately parallel to {@see Wallet}, never
 * attached to it or to a Business Account — see the migration that creates
 * this table for why. Three buckets, exactly as the original keeps buckets
 * rather than derived totals:
 *
 *   - `total` — everything ever credited less everything ever debited.
 *   - `reserved` — set aside against a withdrawal not yet paid or released.
 *   - `recovery` — the outstanding amount owed back to Feriwala, raised when a
 *     reversal arrives after the money was already settled and there was not
 *     enough available to take it back directly (P13-25's "controlled debt",
 *     never a negative balance). It is not paid down by later settlements —
 *     {@see availableBalance()} nets it out of every one of them automatically,
 *     for as long as it stands.
 *
 * No method here writes. Balances move only through
 * {@see SupplierWalletService}, which changes this
 * row and writes the immutable {@see SupplierLedgerEntry} in the same
 * transaction.
 *
 * @property int $id
 * @property string $public_id
 * @property int $supplier_id
 * @property string $currency_code
 * @property Money $total
 * @property Money $reserved
 * @property Money $recovery
 * @property CarbonImmutable $created_at
 * @property-read Supplier $supplier
 */
class SupplierWallet extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total' => MoneyCast::class,
            'reserved' => MoneyCast::class,
            'recovery' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<SupplierLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<SupplierWithdrawal, $this>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(SupplierWithdrawal::class)->orderByDesc('id');
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency_code);
    }

    /**
     * What the Supplier can request a withdrawal against right now.
     *
     * Everything present, less what is already reserved for another
     * withdrawal and less any outstanding recovery — which is exactly what
     * stops a request for money that is needed to make good an earlier
     * over-settlement, with no separate check anywhere else.
     */
    public function availableBalance(): Money
    {
        return $this->floored(
            $this->total->minus($this->reserved)->minus($this->recovery)
        );
    }

    public function hasOutstandingRecovery(): bool
    {
        return $this->recovery->isPositive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toBalances(): array
    {
        return [
            'currency' => $this->currency_code,
            'total' => $this->total->jsonSerialize(),
            'reserved' => $this->reserved->jsonSerialize(),
            'recovery' => $this->recovery->jsonSerialize(),
            'available' => $this->availableBalance()->jsonSerialize(),
            'has_outstanding_recovery' => $this->hasOutstandingRecovery(),
        ];
    }

    protected function floored(Money $amount): Money
    {
        return $amount->isNegative() ? Money::zero($this->currency()) : $amount;
    }
}
