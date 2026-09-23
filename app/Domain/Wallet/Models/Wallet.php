<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Enums\WalletBalanceState;
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
 * @property Money $total
 * @property Money $required_deposit
 * @property Money $minimum_balance
 * @property Money $reserved
 * @property Money $pending
 * @property Money $hold
 * @property Money $cod_receivable
 * @property bool $deposit_usable_for_charges
 * @property int|null $deposit_rule_id
 * @property CarbonImmutable|null $obligation_captured_at
 * @property CarbonImmutable|null $deposit_due_at
 * @property CarbonImmutable|null $shortfall_since
 * @property CarbonImmutable|null $grace_ends_at
 * @property WalletBalanceState $balance_state
 * @property CarbonImmutable|null $balance_checked_at
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
            'total' => MoneyCast::class,
            'required_deposit' => MoneyCast::class,
            'minimum_balance' => MoneyCast::class,
            'deposit_usable_for_charges' => 'boolean',
            'deposit_reserved_until_cancellation' => 'boolean',
            'obligation_captured_at' => 'immutable_datetime',
            'deposit_due_at' => 'immutable_datetime',
            'shortfall_since' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'balance_state' => WalletBalanceState::class,
            'balance_checked_at' => 'immutable_datetime',
            'reserved' => MoneyCast::class,
            'pending' => MoneyCast::class,
            'hold' => MoneyCast::class,
            'cod_receivable' => MoneyCast::class,
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

    /**
     * The rule the current obligation was captured from (§24.1).
     *
     * Read for what it **authorises** — which of §24.3's graded actions may be
     * taken — never for its figures. Those were captured; going back to the rule
     * for them would be the drift capture exists to prevent.
     *
     * @return BelongsTo<DepositRule, $this>
     */
    public function depositRule(): BelongsTo
    {
        return $this->belongsTo(DepositRule::class, 'deposit_rule_id');
    }

    /**
     * Everything §24.3 has taken away and not yet given back.
     *
     * @return HasMany<WalletRestriction, $this>
     */
    public function restrictions(): HasMany
    {
        return $this->hasMany(WalletRestriction::class)->orderByDesc('id');
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
            $this->total
                ->minus($this->reserved)
                ->minus($this->hold)
                ->minus($this->pending)
                ->minus($this->minimum_balance)
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
            $this->total
                ->minus($this->reserved)
                ->minus($this->hold)
                ->minus($this->pending)
                ->minus($this->minimum_balance)
                ->minus($this->required_deposit)
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
            : $this->required_deposit;
    }

    /**
     * Everything the account is standing surety for (§24.1, §24.2).
     *
     * The deposit and the minimum balance together — what the wallet has to hold
     * before any of it is the account's to move.
     */
    public function reservedObligation(): Money
    {
        return $this->required_deposit->plus($this->minimum_balance);
    }

    /**
     * Whether the wallet is holding at least the deposit it is required to.
     */
    public function meetsRequiredDeposit(): bool
    {
        return $this->total->greaterThanOrEqualTo($this->required_deposit);
    }

    /**
     * Whether the wallet is holding everything §24 asks of it.
     *
     * Both obligations at once, because meeting one and not the other is not
     * meeting the requirement — it is the condition §24.3 acts on.
     */
    public function meetsObligation(): bool
    {
        return $this->total->greaterThanOrEqualTo($this->reservedObligation());
    }

    /**
     * How much the account would have to add to meet its required deposit.
     */
    public function shortfall(): Money
    {
        return $this->floored($this->required_deposit->minus($this->total));
    }

    /**
     * How much it would have to add to meet everything §24 asks of it.
     */
    public function obligationShortfall(): Money
    {
        return $this->floored($this->reservedObligation()->minus($this->total));
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
            'total' => $this->total->jsonSerialize(),
            'usable' => $this->usableBalance()->jsonSerialize(),
            'available_for_withdrawal' => $this->availableForWithdrawal()->jsonSerialize(),
            'required_deposit' => $this->required_deposit->jsonSerialize(),

            // §24.2 lists this beside the deposit, not instead of it: the part
            // that has to stay, spendable on nothing and withdrawable never.
            'minimum_balance' => $this->minimum_balance->jsonSerialize(),

            'reserved' => $this->reserved->jsonSerialize(),
            'pending' => $this->pending->jsonSerialize(),
            'hold' => $this->hold->jsonSerialize(),
            'cod_receivable' => $this->cod_receivable->jsonSerialize(),
            'deposit_usable_for_charges' => $this->deposit_usable_for_charges,
            'meets_required_deposit' => $this->meetsRequiredDeposit(),
            'meets_obligation' => $this->meetsObligation(),
            'shortfall' => $this->shortfall()->jsonSerialize(),
            'obligation_shortfall' => $this->obligationShortfall()->jsonSerialize(),
            'deposit_due_at' => $this->deposit_due_at?->toIso8601String(),

            // §24.3's state, carried with a label so no screen has to infer it
            // from the figures — or express it in colour alone (§33.9).
            'state' => $this->balance_state->value,
            'state_label' => $this->balance_state->label(),
            'state_tone' => $this->balance_state->tone(),
            'grace_ends_at' => $this->grace_ends_at?->toIso8601String(),
            'shortfall_since' => $this->shortfall_since?->toIso8601String(),
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
