<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Wallet\Actions\ExportWalletStatement;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Queries\WalletStatement;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The account's own wallet and statement (§23, §33.7).
 *
 * Self-scoped by construction. The wallet is reached through the signed-in
 * person's account, never by looking an identifier up and checking afterwards —
 * so there is nothing in the URL to change, and a business user has no way to
 * ask for somebody else's money (§31.3).
 *
 * Every figure on the screen is worked out on the server. §36.1 is not a style
 * preference here: a browser that added up a column of debits would be a second
 * opinion about a balance, and the wallet would then have two.
 *
 * Nothing here writes. A member reads their wallet; the postings that change it
 * come from the events that caused them.
 */
class WalletController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WalletStatement $statement,
    ) {}

    public function show(Request $request): Response
    {
        $account = $this->businessAccountFor($request);
        $wallet = $this->walletFor($account);

        $filters = $this->statement->acceptedFilters($request->all());

        return Inertia::render('wallet/show', [
            'balances' => $wallet->toBalances(),

            // Never with the internal note. The account holder is the person a
            // staff note is *about* (§23.2, §7.3).
            'transactions' => $this->statement->paginate($wallet, $filters)
                ->through(fn (WalletTransaction $transaction) => $this->statement->row($transaction)),
            'filters' => $filters,
            'options' => $this->statement->options(),
        ]);
    }

    /**
     * One movement, in full.
     */
    public function transaction(Request $request, string $transaction): Response
    {
        $account = $this->businessAccountFor($request);
        $wallet = $this->walletFor($account);

        $record = WalletTransaction::query()
            // Scoped to this wallet before the identifier is used, so a
            // borrowed public id finds nothing rather than finding somebody
            // else's transaction and being refused afterwards (§31.3).
            ->where('wallet_id', $wallet->id)
            ->where('public_id', $transaction)
            ->with(['ledgerEntries', 'payment:id,reference,public_id'])
            ->firstOrFail();

        return Inertia::render('wallet/transaction', [
            'transaction' => [
                ...$this->statement->row($record),
                'payment_reference' => $record->payment?->reference,
                'currency' => $record->currency_code,
                'entries' => $record->ledgerEntries
                    ->map(fn ($entry) => [
                        'reference' => $entry->reference,
                        'debit' => $entry->debit_minor->jsonSerialize(),
                        'credit' => $entry->credit_minor->jsonSerialize(),
                        'balance_before' => $entry->balance_before_minor->jsonSerialize(),
                        'balance_after' => $entry->balance_after_minor->jsonSerialize(),
                        'at' => $entry->created_at->toIso8601String(),
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * The same statement, as a file (§33.7).
     */
    public function export(Request $request, ExportWalletStatement $export): StreamedResponse
    {
        $account = $this->businessAccountFor($request);

        return $export->handle(
            $this->walletFor($account),
            $this->statement->acceptedFilters($request->all()),
        );
    }

    /**
     * The account's wallet.
     *
     * A wallet opens with the activation and is never created on demand, so an
     * account without one is an account that has not been activated — and the
     * §5.4 gate has already turned that person back before this runs.
     */
    protected function walletFor(BusinessAccount $account): Wallet
    {
        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->where('business_account_id', $account->id)
            ->firstOrFail();

        return $wallet;
    }
}
