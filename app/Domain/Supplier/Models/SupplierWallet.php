<?php

namespace App\Domain\Supplier\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Supplier\Actions\SupplierWalletService;
use App\Domain\Wallet\Enums\BalanceType;
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
 * @property Money $platform_credit the part of `total` that can be spent in the platform but never withdrawn
 * @property Money $reserved
 * @property Money $reserved_platform_credit the part of `reserved` that came from platform credit
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
            'platform_credit' => MoneyCast::class,
            'reserved_platform_credit' => MoneyCast::class,
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

    /*
     * The two kinds of money, and where each sits (D-new) — the same rules as an
     * account wallet's. `platform_credit` is the stored part of `total` that is
     * spendable in Banij and never withdrawable; the rest is withdrawable money.
     * Both are *holdings*. What may be spent or withdrawn is the holding less
     * what a reservation has claimed from it and, for a withdrawal, less any
     * outstanding recovery — so nothing becomes withdrawable merely because it is
     * not platform credit.
     */

    /**
     * Platform credit held: spendable inside Banij, never withdrawable.
     *
     * Never more than the wallet holds, so a total that fell without it cannot
     * make the typed split exceed it.
     */
    public function platformCredit(): Money
    {
        return $this->platform_credit->greaterThan($this->total) ? $this->total : $this->platform_credit;
    }

    /**
     * Withdrawable money held: everything that is not platform credit — derived,
     * never stored, so every balance that predates typed balances is
     * withdrawable by construction. A holding, not an availability.
     */
    public function withdrawableHolding(): Money
    {
        return $this->total->minus($this->platformCredit());
    }

    /** The part of the reserved bucket that came from platform credit. */
    public function reservedPlatformCredit(): Money
    {
        return $this->smaller($this->reserved_platform_credit, $this->reserved);
    }

    /** The part of the reserved bucket that came from withdrawable money. */
    public function reservedWithdrawable(): Money
    {
        return $this->reserved->minus($this->reservedPlatformCredit());
    }

    /** Platform credit that no reservation has claimed. */
    public function platformCreditFree(): Money
    {
        return $this->floored($this->platformCredit()->minus($this->reservedPlatformCredit()));
    }

    /** Withdrawable money that no reservation has claimed. */
    public function withdrawableFree(): Money
    {
        return $this->floored($this->withdrawableHolding()->minus($this->reservedWithdrawable()));
    }

    /**
     * Platform credit that can be spent right now: unclaimed, and within what
     * the wallet as a whole may spend (an outstanding recovery stands against it).
     */
    public function platformCreditAvailable(): Money
    {
        return $this->smaller($this->platformCreditFree(), $this->availableBalance());
    }

    /**
     * What a withdrawal may claim right now: withdrawable money that no
     * reservation holds, less the outstanding recovery that is owed back to
     * Banij, and never more than the wallet could spend.
     */
    public function availableForWithdrawal(): Money
    {
        return $this->smaller(
            $this->floored($this->withdrawableFree()->minus($this->recovery)),
            $this->availableBalance(),
        );
    }

    /**
     * Split an amount between the two kinds of money. Left open, platform
     * credit goes first and withdrawable money pays only the rest; with `$only`
     * the whole amount comes from that one kind or the call fails — never from
     * the other.
     *
     * @return array{0: Money, 1: Money} platform credit part, withdrawable part
     *
     * @throws \LogicException when the kind(s) allowed cannot cover the amount
     */
    public function allocateSpend(Money $amount, ?BalanceType $only = null): array
    {
        $platformFree = $this->platformCreditFree();
        $withdrawableFree = $this->withdrawableFree();
        $zero = Money::zero($this->currency());

        if ($only === BalanceType::PlatformCredit) {
            [$platform, $withdrawable] = [$amount, $zero];
        } elseif ($only === BalanceType::Withdrawable) {
            [$platform, $withdrawable] = [$zero, $amount];
        } else {
            $platform = $this->smaller($amount, $platformFree);
            $withdrawable = $amount->minus($platform);
        }

        if ($platform->greaterThan($platformFree) || $withdrawable->greaterThan($withdrawableFree)) {
            throw new \LogicException('The kind(s) of money allowed cannot cover this amount.');
        }

        return [$platform, $withdrawable];
    }

    protected function smaller(Money $a, Money $b): Money
    {
        return $a->lessThan($b) ? $a : $b;
    }

    /**
     * @return array<string, mixed>
     */
    public function toBalances(): array
    {
        return [
            'currency' => $this->currency_code,
            'total' => $this->total->jsonSerialize(),

            // What can be done with each kind now; the holdings follow.
            'platform_credit' => $this->platformCreditAvailable()->jsonSerialize(),
            'withdrawable' => $this->availableForWithdrawal()->jsonSerialize(),
            'platform_credit_held' => $this->platformCredit()->jsonSerialize(),
            'withdrawable_held' => $this->withdrawableHolding()->jsonSerialize(),
            'reserved_platform_credit' => $this->reservedPlatformCredit()->jsonSerialize(),
            'reserved_withdrawable' => $this->reservedWithdrawable()->jsonSerialize(),
            'reserved' => $this->reserved->jsonSerialize(),
            'recovery' => $this->recovery->jsonSerialize(),
            'available' => $this->availableBalance()->jsonSerialize(),
            'available_for_withdrawal' => $this->availableForWithdrawal()->jsonSerialize(),
            'has_outstanding_recovery' => $this->hasOutstandingRecovery(),
        ];
    }

    protected function floored(Money $amount): Money
    {
        return $amount->isNegative() ? Money::zero($this->currency()) : $amount;
    }
}
