<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Domain\Wallet\Queries\ResolveDepositRule;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Turn the rule that applies today into what this account is held to (§24.1).
 *
 * The one place a policy becomes a promise. Everything downstream — the balance
 * a screen shows, the restrictions §24.3 applies, whether a withdrawal is
 * allowed — reads the **captured** figures on the wallet, never the rule table.
 * That is what makes an obligation reproducible: raising a minimum balance in
 * June cannot reach back and change what an account was restricted for in March.
 *
 * Capturing happens on events, not on a timer: activation, a package change, a
 * service being set up, an administrator deliberately reapplying the rules.
 * Between those the account is held to what it agreed to.
 *
 * Idempotent. Capturing the same figures from the same rule twice writes no
 * second history row and moves no deadline, so an activation that runs again
 * after a retry does not quietly extend the time somebody has to pay.
 */
class CaptureDepositObligation
{
    public const ACTIVATION = 'activation';

    public const PACKAGE_CHANGE = 'package_change';

    public const SERVICE_SETUP = 'service_setup';

    public const REAPPLIED = 'reapplied';

    public function __construct(
        protected ResolveDepositRule $rules,
        protected DatabaseManager $database,
    ) {}

    /**
     * Capture what this account is required to hold, or record that nothing is.
     */
    public function handle(
        BusinessAccount $account,
        string $source = self::REAPPLIED,
        ?User $actor = null,
        ?CarbonImmutable $at = null,
    ): ?WalletDepositObligation {
        $at ??= CarbonImmutable::now();

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

        if ($wallet === null) {
            // A wallet opens with the activation and is never created on
            // demand; an account without one has nothing to be held to yet.
            return null;
        }

        $rule = $this->rules->for($account, $at);
        $currency = Currency::from($wallet->currency_code);

        $nothing = Money::zero($currency);

        $figures = [
            'deposit_rule_id' => $rule?->id,
            'required_deposit_minor' => $rule === null ? $nothing : $rule->required_initial_deposit_minor,
            'minimum_balance_minor' => $rule === null ? $nothing : $rule->minimum_balance_minor,
            'required_top_up_minor' => $rule === null ? $nothing : $rule->required_top_up_minor,
            'low_balance_threshold_minor' => $rule?->low_balance_threshold_minor,
            'critical_balance_threshold_minor' => $rule?->critical_balance_threshold_minor,
            'grace_period_days' => $rule?->grace_period_days,

            /*
             * §24.4. Until P2-18 gives a rule its own answer, a deposit is
             * spendable on services: the stricter reading would lock money away
             * on the strength of a choice nobody has made.
             */
            'deposit_usable_for_charges' => true,
        ];

        if ($this->alreadyHolds($wallet, $figures)) {
            return null;
        }

        return $this->database->transaction(function () use (
            $wallet, $account, $figures, $rule, $source, $actor, $at
        ) {
            $dueAt = $rule?->deposit_deadline_days === null
                ? null
                : $at->addDays($rule->deposit_deadline_days);

            $obligation = WalletDepositObligation::create([
                'wallet_id' => $wallet->id,
                'business_account_id' => $account->id,
                'currency_code' => $wallet->currency_code,
                'source' => $source,
                'actor_id' => $actor?->id,
                'deposit_due_at' => $dueAt,
                'captured_at' => $at,

                ...$figures,
            ]);

            /*
             * The wallet carries the current obligation because that is what a
             * screen and a posting need to read. Written straight rather than
             * through the posting service on purpose: none of these columns is
             * a balance, and no value moves. What moves is what the account is
             * required to keep.
             */
            $wallet->forceFill([
                'required_deposit_minor' => $figures['required_deposit_minor'],
                'minimum_balance_minor' => $figures['minimum_balance_minor'],
                'deposit_usable_for_charges' => $figures['deposit_usable_for_charges'],
                'deposit_rule_id' => $rule?->id,
                'obligation_captured_at' => $at,
                'deposit_due_at' => $dueAt,
            ])->save();

            return $obligation;
        });
    }

    /**
     * Whether the wallet is already held to exactly these figures.
     *
     * Compared on the figures rather than on the rule id: a rule closed and
     * replaced by an identical one is not a new obligation, and treating it as
     * one would reset a deadline somebody is counting down.
     *
     * @param  array<string, mixed>  $figures
     */
    protected function alreadyHolds(Wallet $wallet, array $figures): bool
    {
        if ($wallet->obligation_captured_at === null) {
            return false;
        }

        return $wallet->required_deposit_minor->equals($figures['required_deposit_minor'])
            && $wallet->minimum_balance_minor->equals($figures['minimum_balance_minor'])
            && $wallet->deposit_usable_for_charges === $figures['deposit_usable_for_charges']
            && $wallet->deposit_rule_id === $figures['deposit_rule_id'];
    }
}
