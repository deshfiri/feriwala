<?php

namespace App\Http\Controllers\Api\Mobile\V1\Wallet;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletRestriction;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Queries\WalletStatement;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Wallet\WalletStatementRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Http\Resources\Mobile\Wallet\WalletResource;
use App\Http\Resources\Mobile\Wallet\WalletTransactionDetailResource;
use App\Http\Resources\Mobile\Wallet\WalletTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The account's own wallet and statement (§23, §33.7).
 *
 * Self-scoped by construction, exactly as the web wallet is: the wallet is
 * reached through the signed-in person's account, never by looking an
 * identifier up and checking afterwards, so there is nothing to change in the
 * URL and nobody else's money to ask for (§31.3). Read-only: the postings that
 * change a wallet come from the events that caused them.
 */
class WalletController extends MobileController
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected WalletStatement $statement,
    ) {}

    /**
     * Wallet balances
     *
     * Every figure the wallet screen shows, worked out on the server, together
     * with any restriction currently standing on it. `usable` is what can be
     * spent now; `withdrawable` is exactly what may be withdrawn.
     *
     * @response array{data: WalletResource, restrictions: list<array{stage: string, stage_label: string, stage_tone: string, started_at: string, reason: string|null}>}
     */
    public function show(Request $request): JsonResponse
    {
        $wallet = $this->walletFor($request);

        return new JsonResponse([
            'data' => new WalletResource($wallet),
            // Only what is standing: a restriction lifted last week is history,
            // and history belongs on the statement.
            'restrictions' => $wallet->restrictions()
                ->standing()
                ->get()
                ->map(fn (WalletRestriction $restriction) => [
                    'stage' => $restriction->stage->value,
                    'stage_label' => $restriction->stage->label(),
                    'stage_tone' => $restriction->stage->tone(),
                    'started_at' => $restriction->started_at->toIso8601String(),
                    'reason' => $restriction->reason,
                ])
                ->all(),
        ]);
    }

    /**
     * Usable balance
     *
     * The one figure a header shows: what the account can use now. Never cached.
     *
     * @response array{usable: MoneyResource}
     */
    public function usable(Request $request): JsonResponse
    {
        return (new JsonResponse(['usable' => new MoneyResource($this->walletFor($request)->usableBalance())]))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Statement
     *
     * The wallet's movements, newest first, 20 to a page. Filters that mean
     * nothing are ignored; `filters` echoes back what was actually applied and
     * `options` lists the values each filter accepts.
     *
     * @response array{data: list<WalletTransactionResource>, links: array<string, string|null>, meta: array<string, mixed>, filters: array<string, string>, options: array<string, list<array{value: string, label: string}>>}
     */
    public function transactions(WalletStatementRequest $request): AnonymousResourceCollection
    {
        $wallet = $this->walletFor($request);
        $filters = $this->statement->acceptedFilters($request->validated());

        return WalletTransactionResource::collection($this->statement->paginate($wallet, $filters))
            ->additional(['filters' => $filters, 'options' => $this->statement->options()]);
    }

    /**
     * Movement detail
     *
     * One movement in full, with the ledger entries it produced. A movement
     * that is not in this account's wallet answers `404`.
     */
    public function transaction(Request $request, string $transaction): WalletTransactionDetailResource
    {
        $wallet = $this->walletFor($request);

        $record = WalletTransaction::query()
            // Scoped to this wallet before the identifier is used, so a
            // borrowed public id finds nothing rather than being refused later.
            ->where('wallet_id', $wallet->id)
            ->where('public_id', $transaction)
            ->with(['ledgerEntries', 'payment:id,reference,public_id'])
            ->firstOrFail();

        return new WalletTransactionDetailResource($record);
    }

    /**
     * A wallet opens with activation and is never created on demand, so an
     * account without one has not been activated — which `mobile.activated`
     * has already turned back.
     */
    protected function walletFor(Request $request): Wallet
    {
        return Wallet::query()
            ->where('business_account_id', $this->businessAccountFor($request)->id)
            ->firstOrFail();
    }
}
