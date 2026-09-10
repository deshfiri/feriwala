<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Concerns\HasPublicId;
use App\Domain\Account\Models\BusinessAccount;
use App\Support\Money\Currency;
use App\Support\Money\Money;
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
 * @property Money $reserved_minor
 * @property Money $pending_minor
 * @property Money $hold_minor
 * @property Money $cod_receivable_minor
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
     * Everything present, less every claim that is not spendable: the minimum
     * balance and explicit reservations, money frozen under review, and money
     * credited but not yet cleared.
     *
     * The required deposit is **not** subtracted here. §24.4 makes a deposit's
     * usability configurable, and a deposit that may cover charges is spendable
     * by definition; what it may never do is leave as a withdrawal.
     */
    public function usableBalance(): Money
    {
        return $this->floored(
            $this->total_minor
                ->minus($this->reserved_minor)
                ->minus($this->hold_minor)
                ->minus($this->pending_minor)
        );
    }

    /**
     * What the account could actually withdraw (§24.2).
     *
     * The usable balance less the deposit it has to keep in place. A withdrawal
     * that emptied the required deposit would leave the account unable to trade
     * the moment it succeeded.
     */
    public function availableForWithdrawal(): Money
    {
        return $this->floored($this->usableBalance()->minus($this->required_deposit_minor));
    }

    /**
     * Whether the wallet is holding at least the deposit it is required to.
     */
    public function meetsRequiredDeposit(): bool
    {
        return $this->total_minor->greaterThanOrEqualTo($this->required_deposit_minor);
    }

    /**
     * How much the account would have to add to meet its required deposit.
     */
    public function shortfall(): Money
    {
        return $this->floored($this->required_deposit_minor->minus($this->total_minor));
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
            'reserved' => $this->reserved_minor->jsonSerialize(),
            'pending' => $this->pending_minor->jsonSerialize(),
            'hold' => $this->hold_minor->jsonSerialize(),
            'cod_receivable' => $this->cod_receivable_minor->jsonSerialize(),
            'meets_required_deposit' => $this->meetsRequiredDeposit(),
            'shortfall' => $this->shortfall()->jsonSerialize(),
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
