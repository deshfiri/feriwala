<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Queries\WalletStatement;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The statement as a file (§33.7, P2-8).
 *
 * Streamed rather than assembled, because an export is the one read with no page
 * size to hide behind: a wallet with five years of history must not have to fit
 * in memory before the first byte is sent.
 *
 * Money is written as its exact decimal string — never a float, and never the
 * formatted display value with its symbol and separators, which no spreadsheet
 * can add up. Debit and credit stay in their own columns, so a total is a column
 * sum rather than a sign convention the reader has to know.
 *
 * The internal note is not a column at all. Not blank, not withheld: absent, so
 * there is no header suggesting the file was trimmed for whoever asked for it.
 */
class ExportWalletStatement
{
    public function __construct(
        protected WalletStatement $statement,
    ) {}

    /**
     * @param  array<string, string>  $filters
     */
    public function handle(Wallet $wallet, array $filters): StreamedResponse
    {
        $filename = 'wallet-statement-'.$wallet->public_id.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($wallet, $filters) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            // A byte-order mark, so a spreadsheet opens Bangla descriptions as
            // Bangla rather than as mojibake (D6).
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Date', 'Reference', 'Type', 'Description', 'Direction',
                'Debit', 'Credit', 'Balance after', 'Currency', 'Status',
            ]);

            foreach ($this->statement->stream($wallet, $filters) as $transaction) {
                fputcsv($handle, $this->line($transaction));
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function line(WalletTransaction $transaction): array
    {
        $entry = $transaction->ledgerEntries->last();

        return [
            $transaction->created_at?->toDateTimeString() ?? '',
            $this->safe($transaction->reference),
            $this->safe($transaction->type->label()),
            $this->safe($transaction->description),
            $this->safe($transaction->direction->label()),

            // Empty rather than zero where no value moved: a reservation is not
            // a debit of nothing, it is not a debit. Written as the exact
            // decimal, so the column adds up in a spreadsheet.
            $entry === null ? '' : $entry->debit_minor->toDecimal(),
            $entry === null ? '' : $entry->credit_minor->toDecimal(),
            $entry === null ? '' : $entry->balance_after_minor->toDecimal(),

            $transaction->currency_code,
            $this->safe($transaction->status->label()),
        ];
    }

    /**
     * Stop a cell being read as a formula.
     *
     * A description the account holder typed can begin with `=` or `+`, and a
     * spreadsheet will happily execute it when the file is opened. Prefixing
     * with an apostrophe is what makes the cell text — the same guard every
     * export needs, applied at the one place cells are written.
     */
    protected function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
