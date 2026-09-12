<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletBalanceState;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDepositObligation;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Where a wallet stands against what it is required to hold (§24.1, §24.3).
 *
 * The reading, not the acting. This decides which of three states a wallet is in
 * and, when it first falls short, stamps the deadline its grace period gives it.
 * What happens next is {@see EnforceBalanceRules}, which is deliberately a
 * separate step: "is this account short" and "what do we do about it" are
 * different questions, and only the second one restricts anybody.
 *
 * **The deadline is stamped once.** A grace period is a promise — "you have
 * fourteen days" — and a promise recalculated on every sweep is a deadline that
 * moves whenever somebody edits a setting. So it is written when the shortfall
 * begins, from the grace period captured in the obligation of the day, and left
 * alone until the account is back above the line.
 *
 * Idempotent by construction: running it twice writes the same state, and the
 * second run does not extend anybody's time.
 */
class EvaluateWalletBalance
{
    public function __construct(
        protected DatabaseManager $database,
    ) {}

    /**
     * Read the wallet's state and record it, without acting on it.
     */
    public function handle(Wallet $wallet, ?CarbonImmutable $at = null): WalletBalanceState
    {
        $at ??= CarbonImmutable::now();

        $state = $this->stateOf($wallet);

        return $this->database->transaction(function () use ($wallet, $state, $at) {
            /** @var Wallet $locked */
            $locked = Wallet::query()->lockForUpdate()->findOrFail($wallet->id);

            $changes = [
                'balance_state' => $state->value,
                'balance_checked_at' => $at,
            ];

            if ($state->isShort()) {
                /*
                 * Only stamped if it is not already. A shortfall that started on
                 * the 3rd started on the 3rd, however many sweeps have run since
                 * — and the deadline that came with it stands.
                 */
                if ($locked->shortfall_since === null) {
                    $changes['shortfall_since'] = $at;
                    $changes['grace_ends_at'] = $this->graceEndsAt($locked, $at);
                }
            } else {
                // Back above the line: the clock is not paused, it is gone. A
                // shortfall next month is a new shortfall with its own grace.
                $changes['shortfall_since'] = null;
                $changes['grace_ends_at'] = null;
            }

            $locked->forceFill($changes)->save();

            $wallet->setRawAttributes($locked->getAttributes(), sync: true);

            return $state;
        });
    }

    /**
     * Which state the balance puts this wallet in, reading only the figures.
     *
     * Separate from `handle()` so a screen can ask without writing anything.
     */
    public function stateOf(Wallet $wallet): WalletBalanceState
    {
        if (! $wallet->meetsObligation()) {
            return WalletBalanceState::Critical;
        }

        $threshold = $this->lowThreshold($wallet);

        if ($threshold !== null && $wallet->total_minor->lessThan($threshold)) {
            return WalletBalanceState::Low;
        }

        return WalletBalanceState::Healthy;
    }

    /**
     * Whether the grace period this wallet was given has run out.
     *
     * A wallet with no deadline has no grace to run out of: §24.1 makes the
     * period optional, and an account that was never given one is short from
     * the moment it falls short.
     */
    public function graceHasExpired(Wallet $wallet, ?CarbonImmutable $at = null): bool
    {
        if (! $wallet->balance_state->isShort()) {
            return false;
        }

        return $wallet->grace_ends_at === null
            || ($at ?? CarbonImmutable::now())->greaterThanOrEqualTo($wallet->grace_ends_at);
    }

    /**
     * The low threshold this wallet was captured with (§24.1).
     *
     * From the obligation, not from the rule table: an account is measured
     * against what it agreed to.
     */
    protected function lowThreshold(Wallet $wallet): ?Money
    {
        return $this->obligation($wallet)?->low_balance_threshold_minor;
    }

    /**
     * When the grace period ends, from what was captured with the obligation.
     */
    protected function graceEndsAt(Wallet $wallet, CarbonImmutable $at): ?CarbonImmutable
    {
        $days = $this->obligation($wallet)?->grace_period_days;

        return $days === null ? null : $at->addDays($days);
    }

    /**
     * The obligation this wallet is currently held to.
     */
    protected function obligation(Wallet $wallet): ?WalletDepositObligation
    {
        /** @var WalletDepositObligation|null $obligation */
        $obligation = WalletDepositObligation::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->first();

        return $obligation;
    }
}
