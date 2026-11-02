<?php

namespace App\Http\Controllers\Api\Mobile\V1\WalletTopups;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Wallet\Actions\ExportWalletStatement;
use App\Domain\Wallet\Actions\PlanWalletTopUp;
use App\Domain\Wallet\Actions\StartWalletTopUp;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Queries\WalletStatement;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\WalletTopups\ExportStatementRequest;
use App\Http\Requests\Mobile\WalletTopups\StartTopUpRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Putting money into the account's own wallet, and taking its statement away (§24, §26.3, §33.7).
 *
 * Self-scoped like the rest of the wallet: the account comes from the signed-in
 * person, so nothing in any URL can be changed to reach another account's money.
 * The server decides everything that matters; the app sends one amount and one
 * gateway. Settlement is not here: the gateway reports back to the same return
 * and IPN endpoints the web checkout uses.
 */
class WalletTopUpController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * Top-up options
     *
     * What the top-up screen needs: the least that may be paid in now, a suggested
     * amount (what gets the account back to where it should be), and the gateways
     * that can take Taka, each with a currency-conversion notice when it applies.
     * Every figure is worked out on the server.
     *
     * @response array{minimum: MoneyResource, suggested: MoneyResource, gateways: list<array{value: string, label: string, conversion_notice: array<string, mixed>|null}>}
     */
    public function options(Request $request, PlanWalletTopUp $planner, PaymentGatewayManager $gateways): JsonResponse
    {
        $wallet = $this->walletFor($request);

        return new JsonResponse([
            'minimum' => new MoneyResource($planner->minimumFor($wallet)),
            'suggested' => new MoneyResource($wallet->obligationShortfall()),
            'gateways' => array_map(fn (string $gateway) => [
                'value' => $gateway,
                'label' => $gateway,
                'conversion_notice' => $gateways->conversionNotice($gateway, Currency::base()),
            ], $gateways->availableFor(Currency::base())),
        ]);
    }

    /**
     * Start a top-up
     *
     * Plans the payment, records it and opens a session with the chosen gateway.
     * Open `redirect_url` in an in-app browser; the wallet is credited only when
     * the gateway's settlement lands, never by this call. `plan` says what the
     * money will do: how much closes a required deposit and how much is spendable.
     * Errors: `422` when the amount is below the minimum, not positive, or has more
     * than two decimals, or when the gateway is not enabled or is unavailable.
     *
     * @response 200 array{redirect_url: string, payment: array{id: string, reference: string}, plan: array{purpose: string, amount: MoneyResource, to_obligation: MoneyResource, to_usable: MoneyResource, minimum: MoneyResource}}
     */
    public function store(StartTopUpRequest $request, StartWalletTopUp $start): JsonResponse
    {
        $started = $start->handle(
            $this->businessAccountFor($request),
            $this->walletFor($request),
            DecimalAmount::parse($request->validated('amount')),
            $request->validated('gateway'),
        );

        return new JsonResponse([
            'redirect_url' => $started['redirect_url'],
            'payment' => [
                'id' => $started['payment']->public_id,
                'reference' => $started['payment']->reference,
            ],
            'plan' => [
                'purpose' => $started['plan']->purpose->value,
                'amount' => new MoneyResource($started['plan']->amount),
                'to_obligation' => new MoneyResource($started['plan']->toObligation),
                'to_usable' => new MoneyResource($started['plan']->toUsable),
                'minimum' => new MoneyResource($started['plan']->minimum),
            ],
        ]);
    }

    /**
     * Export the statement
     *
     * The wallet statement as a CSV download (UTF-8 with a byte-order mark), under
     * the same filters as the statement list. Money is written as exact decimal
     * strings in separate debit and credit columns; the internal note is never
     * included. Streamed, so a long history is not held in memory.
     *
     * @response string
     */
    public function export(ExportStatementRequest $request, ExportWalletStatement $export, WalletStatement $statement): StreamedResponse
    {
        return $export->handle(
            $this->walletFor($request),
            $statement->acceptedFilters($request->validated()),
        );
    }

    protected function walletFor(Request $request): Wallet
    {
        return Wallet::query()
            ->where('business_account_id', $this->businessAccountFor($request)->id)
            ->firstOrFail();
    }
}
