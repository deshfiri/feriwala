<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Money\Money;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\DB;

/**
 * Re-derives every wallet from its own ledger and says where they disagree
 * (§28.1, P2-9).
 *
 * The ledger and the balance are written together in one transaction, so they
 * should never diverge. "Should never" is not a control. This is the control:
 * it adds up the entries, compares the answer to the wallet, and shouts when
 * they differ — because the failure mode of a financial system is not usually a
 * crash, it is a number that has been quietly wrong for a fortnight.
 *
 * Three things are checked, and they fail differently:
 *
 *   1. **The wallet against its entries.** Credits less debits must equal the
 *      total. A mismatch means something wrote a balance outside the posting
 *      service.
 *   2. **Each entry against itself.** Balance before plus the movement must
 *      equal balance after. A mismatch means the entry was wrong when written.
 *   3. **Each entry against the one before it.** One entry's closing balance is
 *      the next one's opening balance. A gap means an entry is missing, which
 *      the first two checks cannot see.
 *
 * Reads only. It never repairs anything: a sweep that silently corrected a
 * balance would destroy the evidence of whatever caused the drift, and §23.2
 * has a way to put things right that leaves a record.
 */
class VerifyLedgerIntegrity
{
    public function __construct(
        protected LogManager $log,
    ) {}

    /**
     * @return array{checked: int, mismatched: int, problems: array<int, array<string, mixed>>}
     */
    public function handle(): array
    {
        $checked = 0;
        $problems = [];

        Wallet::query()->orderBy('id')->chunkById(100, function ($wallets) use (&$checked, &$problems) {
            foreach ($wallets as $wallet) {
                $checked++;

                foreach ($this->problemsFor($wallet) as $problem) {
                    $problems[] = $problem;
                }
            }
        });

        $mismatched = count(array_unique(array_column($problems, 'wallet')));

        if ($problems !== []) {
            /*
             * Critical, and once per sweep rather than once per problem: a
             * ledger drift is one incident however many rows it touched, and an
             * alert that arrives a thousand times is an alert nobody reads.
             */
            $this->log->channel('wallet')->critical('Ledger integrity check failed', [
                'wallets_checked' => $checked,
                'wallets_mismatched' => $mismatched,
                'problems' => array_slice($problems, 0, 20),
            ]);
        }

        return ['checked' => $checked, 'mismatched' => $mismatched, 'problems' => $problems];
    }

    /**
     * Everything wrong with one wallet, or nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function problemsFor(Wallet $wallet): array
    {
        $problems = [];

        $sums = DB::table('ledger_entries')
            ->where('wallet_id', $wallet->id)
            ->selectRaw('coalesce(sum(credit), 0) as credited, coalesce(sum(debit), 0) as debited')
            ->first();

        // Exact decimal subtraction, never a float: sum(numeric) already comes
        // back from Postgres as a decimal string, and bcmath is what keeps it
        // one all the way through.
        $derived = Money::fromDecimal(
            bcsub((string) ($sums->credited ?? '0'), (string) ($sums->debited ?? '0'), 2),
            $wallet->currency(),
        );

        if (! $derived->equals($wallet->total)) {
            $problems[] = [
                'wallet' => $wallet->public_id,
                'problem' => 'balance_mismatch',
                'stored' => $wallet->total->toDecimal(),
                'derived' => $derived->toDecimal(),
            ];
        }

        $previous = null;

        foreach ($this->entriesFor($wallet) as $entry) {
            if (! $entry->balances()) {
                $problems[] = [
                    'wallet' => $wallet->public_id,
                    'problem' => 'entry_does_not_balance',
                    'entry' => $entry->reference,
                ];
            }

            // The chain. A missing entry shows up here and nowhere else: the
            // sums still add up, but the story has a hole in it.
            if ($previous !== null
                && ! $previous->balance_after->equals($entry->balance_before)) {
                $problems[] = [
                    'wallet' => $wallet->public_id,
                    'problem' => 'broken_chain',
                    'entry' => $entry->reference,
                    'after_previous' => $previous->balance_after->toDecimal(),
                    'before_this' => $entry->balance_before->toDecimal(),
                ];
            }

            $previous = $entry;
        }

        return $problems;
    }

    /**
     * This wallet's entries in the order they were written.
     *
     * By id, not by timestamp: two entries posted in the same second have an
     * order, and only the id knows it.
     *
     * @return iterable<int, LedgerEntry>
     */
    protected function entriesFor(Wallet $wallet): iterable
    {
        return LedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderBy('id')
            ->lazy();
    }
}
