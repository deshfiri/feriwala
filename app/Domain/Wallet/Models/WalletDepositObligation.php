<?php

namespace App\Domain\Wallet\Models;

use App\Casts\MoneyCast;
use App\Domain\Account\Models\BusinessAccount;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one account was required to hold, as decided on one day (§24.1).
 *
 * The rule is the policy; this is the promise. Keeping them apart is what makes
 * a restriction explainable months later: an account held to five thousand in
 * March was held to the rule as it stood in March, and editing that rule in June
 * cannot reach back and change what it was asked for.
 *
 * A row with no rule is meaningful — "nothing was required of this account" is
 * the answer to why nothing was enforced.
 *
 * @property int $id
 * @property int $wallet_id
 * @property int $business_account_id
 * @property int|null $deposit_rule_id
 * @property Money $required_deposit_minor
 * @property Money $minimum_balance_minor
 * @property Money $required_top_up_minor
 * @property string $currency_code
 * @property Money|null $low_balance_threshold_minor
 * @property Money|null $critical_balance_threshold_minor
 * @property int|null $grace_period_days
 * @property bool $deposit_usable_for_charges
 * @property string $source
 * @property int|null $actor_id
 * @property CarbonImmutable|null $deposit_due_at
 * @property CarbonImmutable $captured_at
 */
class WalletDepositObligation extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \RuntimeException(
                'A captured obligation cannot be changed. Capture the next one instead (§24.1).'
            );
        });

        static::deleting(function (): never {
            throw new \RuntimeException(
                'A captured obligation cannot be deleted. It is what the account was held to (§24.1).'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_deposit_minor' => MoneyCast::class,
            'minimum_balance_minor' => MoneyCast::class,
            'required_top_up_minor' => MoneyCast::class,
            'low_balance_threshold_minor' => MoneyCast::class,
            'critical_balance_threshold_minor' => MoneyCast::class,
            'deposit_usable_for_charges' => 'boolean',
            'deposit_due_at' => 'immutable_datetime',
            'captured_at' => 'immutable_datetime',
        ];
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
     * @return BelongsTo<DepositRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DepositRule::class, 'deposit_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Whether this obligation asks for anything.
     */
    public function requiresAnything(): bool
    {
        return $this->required_deposit_minor->isPositive()
            || $this->minimum_balance_minor->isPositive();
    }
}
