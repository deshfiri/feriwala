<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One business account's wallet (§23, §24.2).
 *
 * The stored columns are the primitive buckets; **usable** and **available for
 * withdrawal** are worked out here and nowhere else. §24.2 asks the wallet to
 * distinguish them, which it does — but as arithmetic rather than as columns,
 * because a derived figure kept in a column is a figure that eventually
 * disagrees with the one it was derived from, and nothing says which is right.
 *
 * No method here writes. Balances move only through the ledger posting service,
 * which changes the row and writes the immutable entry in the same transaction
 * (§23.2, §36.1).
 *
 * @property int $id
 * @property string $public_id
 * @property int $business_account_id
 * @property string $currency_code
 * @property Money $total_minor
 * @property Money $required_deposit_minor
 * @property Money $minimum_balance_minor
 * @property Money $reserved_minor
 * @property Money $pending_minor
 * @property Money $hold_minor
 * @property Money $cod_receivable_minor
 * @property bool $deposit_usable_for_charges
 * @property int|null $deposit_rule_id
 * @property CarbonImmutable|null $obligation_captured_at
 * @property CarbonImmutable|null $deposit_due_at
 * @property-read BusinessAccount|null $businessAccount
 */
class Wallet extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_minor' => MoneyCast::class,
            'required_deposit_minor' => MoneyCast::class,
            'minimum_balance_minor' => MoneyCast::class,
            'deposit_usable_for_charges' => 'boolean',
            'obligation_captured_at' => 'immutable_datetime',
            'deposit_due_at' => 'immutable_datetime',
            'reserved_minor' => MoneyCast::class,
            'pending_minor' => MoneyCast::class,
            'hold_minor' => MoneyCast::class,
            'cod_receivable_minor' => MoneyCast::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return HasMany<WalletTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class)->orderByDesc('id');
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency_code);
    }

    /**
     * What the account can spend on services right now (§24.2).
     *
     * Everything present, less every claim that is not spendable: explicit
     * reservations, money frozen under review, money credited but not yet
     * cleared — and the **minimum balance**, which is by definition the part
     * that has to stay. §24.2 lists the reserved minimum balance separately from
     * the deposit precisely because they are different promises.
     *
     * The required deposit is subtracted only when the rule says the deposit may
     * not cover charges (§24.4). A deposit that is usable for service charges is
     * spendable by definition; what it may never do is leave as a withdrawal.
     */
    public function usableBalance(): Money
    {
        return $this->floored(
            $this->total_minor
                ->minus($this->reserved_minor)
                ->minus($this->hold_minor)
                ->minus($this->pending_minor)
                ->minus($this->minimum_balance_minor)
                ->minus($this->depositLockedFromSpending())
        );
    }

    /**
     * What the account could actually withdraw (§24.2).
     *
     * Everything not spoken for, less **both** standing obligations: the deposit
     * it must keep and the minimum balance it must maintain. Neither may leave,
     * whatever §24.4 says about spending them on services — a withdrawal that
     * emptied either would leave the account unable to trade the moment it
     * succeeded.
     *
     * Never more than the spendable balance, and usually less.
     */
    public function availableForWithdrawal(): Money
    {
        return $this->floored(
            $this->total_minor
                ->minus($this->reserved_minor)
                ->minus($this->hold_minor)
                ->minus($this->pending_minor)
                ->minus($this->minimum_balance_minor)
                ->minus($this->required_deposit_minor)
        );
    }

    /**
     * The part of the required deposit that cannot be spent either (§24.4).
     *
     * Zero when the rule allows the deposit to cover service charges, which is
     * the default: locking money away on the strength of a choice nobody has
     * made is the wrong way round.
     */
    public function depositLockedFromSpending(): Money
    {
        return $this->deposit_usable_for_charges
            ? Money::zero($this->currency())
            : $this->required_deposit_minor;
    }

    /**
     * Everything the account is standing surety for (§24.1, §24.2).
     *
     * The deposit and the minimum balance together — what the wallet has to hold
     * before any of it is the account's to move.
     */
    public function reservedObligation(): Money
    {
        return $this->required_deposit_minor->plus($this->minimum_balance_minor);
    }

    /**
     * Whether the wallet is holding at least the deposit it is required to.
     */
    public function meetsRequiredDeposit(): bool
    {
        return $this->total_minor->greaterThanOrEqualTo($this->required_deposit_minor);
    }

    /**
     * Whether the wallet is holding everything §24 asks of it.
     *
     * Both obligations at once, because meeting one and not the other is not
     * meeting the requirement — it is the condition §24.3 acts on.
     */
    public function meetsObligation(): bool
    {
        return $this->total_minor->greaterThanOrEqualTo($this->reservedObligation());
    }

    /**
     * How much the account would have to add to meet its required deposit.
     */
    public function shortfall(): Money
    {
        return $this->floored($this->required_deposit_minor->minus($this->total_minor));
    }

    /**
     * How much it would have to add to meet everything §24 asks of it.
     */
    public function obligationShortfall(): Money
    {
        return $this->floored($this->reservedObligation()->minus($this->total_minor));
    }

    /**
     * The whole picture, for a screen or an API.
     *
     * Assembled here so the account's own wallet page and the administrator's
     * view of it cannot describe the same money differently.
     *
     * @return array<string, mixed>
     */
    public function toBalances(): array
    {
        return [
            'currency' => $this->currency_code,
            'total' => $this->total_minor->jsonSerialize(),
            'usable' => $this->usableBalance()->jsonSerialize(),
            'available_for_withdrawal' => $this->availableForWithdrawal()->jsonSerialize(),
            'required_deposit' => $this->required_deposit_minor->jsonSerialize(),

            // §24.2 lists this beside the deposit, not instead of it: the part
            // that has to stay, spendable on nothing and withdrawable never.
            'minimum_balance' => $this->minimum_balance_minor->jsonSerialize(),

            'reserved' => $this->reserved_minor->jsonSerialize(),
            'pending' => $this->pending_minor->jsonSerialize(),
            'hold' => $this->hold_minor->jsonSerialize(),
            'cod_receivable' => $this->cod_receivable_minor->jsonSerialize(),
            'deposit_usable_for_charges' => $this->deposit_usable_for_charges,
            'meets_required_deposit' => $this->meetsRequiredDeposit(),
            'meets_obligation' => $this->meetsObligation(),
            'shortfall' => $this->shortfall()->jsonSerialize(),
            'obligation_shortfall' => $this->obligationShortfall()->jsonSerialize(),
            'deposit_due_at' => $this->deposit_due_at?->toIso8601String(),
        ];
    }

    /**
     * A derived balance never reads as negative.
     *
     * The claims against a wallet can legitimately exceed what is in it — a hold
     * placed on the whole balance, a required deposit not yet funded — and
     * "you have minus two hundred taka to spend" is not a true statement about
     * spending power. The shortfall says the same thing the right way up.
     */
    protected function floored(Money $amount): Money
    {
        return $amount->isNegative() ? Money::zero($this->currency()) : $amount;
    }
}
