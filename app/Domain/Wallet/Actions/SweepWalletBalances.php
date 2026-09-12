<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Enums\WalletBalanceState;
use App\Domain\Wallet\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * The daily pass over every wallet (§24.3, §41).
 *
 * Balances do not fall below a line by being written to — they fall below it
 * because a deadline arrived, a grace period ran out, or an obligation changed
 * while nobody was looking. So something has to come round and ask, and this is
 * it.
 *
 * Two jobs, in one order that matters: **restore first, then enforce**. An
 * account that paid last night should get its services back before anything
 * else is considered, and doing it the other way round would mean a wallet
 * briefly reaching a stage it had already paid its way out of.
 *
 * Safe to run twice and safe to run beside itself. Everything it calls is
 * idempotent on its own terms — a partial unique index for restrictions, a
 * stamped deadline for grace, a notification sent when a stage is first reached
 * — so a second worker finds the work done rather than doing it again.
 *
 * One wallet's failure never stops the sweep. A thousand accounts should not go
 * unchecked because one of them has a problem, and the one that does is logged
 * loudly enough to find.
 */
class SweepWalletBalances
{
    /** Wallets per chunk. Enough to be worth a query, small enough to hold. */
    public const CHUNK = 100;

    public function __construct(
        protected EvaluateWalletBalance $evaluate,
        protected EnforceBalanceRules $enforce,
        protected RestoreWalletServices $restore,
        protected LogManager $log,
    ) {}

    /**
     * @return array{checked: int, restricted: int, restored: int, failed: int}
     */
    public function handle(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $checked = 0;
        $restricted = 0;
        $restored = 0;
        $failed = 0;

        Wallet::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($wallets) use (
                $at, &$checked, &$restricted, &$restored, &$failed
            ) {
                foreach ($wallets as $wallet) {
                    $checked++;

                    try {
                        $lifted = $this->restore->handle($wallet, $at);

                        if ($lifted !== []) {
                            $restored++;
                        }

                        // Re-read: restoring moved the wallet's own state, and
                        // enforcing against a stale copy would act on a picture
                        // that is one step out of date.
                        $applied = $this->enforce->handle($wallet->refresh(), $at);

                        if ($applied !== []) {
                            $restricted++;
                        }
                    } catch (Throwable $throwable) {
                        $failed++;

                        /*
                         * One account's problem is not a reason to leave the
                         * other nine hundred unchecked. It is a reason to say
                         * so loudly.
                         */
                        $this->log->channel('wallet')->error('Balance sweep failed for a wallet', [
                            'wallet' => $wallet->public_id,
                            'business_account' => $wallet->business_account_id,
                            'error' => $throwable->getMessage(),
                        ]);
                    }
                }
            });

        return [
            'checked' => $checked,
            'restricted' => $restricted,
            'restored' => $restored,
            'failed' => $failed,
        ];
    }

    /**
     * How many wallets are currently short, without changing anything.
     *
     * For a screen that wants to say how much work is waiting.
     */
    public function shortCount(): int
    {
        return Wallet::query()
            ->where('balance_state', WalletBalanceState::Critical->value)
            ->count();
    }
}
