<?php

namespace App\Http\Controllers\Api\Mobile\V1\Withdrawals;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\AccountWithdrawalLimits;
use App\Domain\Withdrawal\Actions\RequestAccountWithdrawal;
use App\Domain\Withdrawal\Enums\AccountWithdrawalStatus;
use App\Domain\Withdrawal\Exceptions\AccountWithdrawalRefused;
use App\Domain\Withdrawal\Models\AccountWithdrawal;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Requests\Mobile\Withdrawals\RequestWithdrawalRequest;
use App\Http\Requests\Mobile\Withdrawals\WithdrawalListRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Http\Resources\Mobile\Withdrawals\WithdrawalDetailResource;
use App\Http\Resources\Mobile\Withdrawals\WithdrawalResource;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The account's own withdrawal requests (§27).
 *
 * Self-scoped by query: every read is restricted to the signed-in person's
 * account before the identifier is used, so another account's withdrawal is a
 * `404` and never a refusal. The server is authoritative for balance, limits and
 * eligibility; the app displays the figures and never works them out.
 */
class WithdrawalController extends MobileController
{
    use ResolvesBusinessAccount;

    /**
     * List withdrawals
     *
     * The account's withdrawal requests, newest first, 20 to a page, optionally
     * filtered by `status`. `statuses` lists the values the filter accepts.
     *
     * @response array{data: list<WithdrawalResource>, links: array<string, string|null>, meta: array<string, mixed>, statuses: list<array{value: string, label: string}>}
     */
    public function index(WithdrawalListRequest $request): AnonymousResourceCollection
    {
        $account = $this->businessAccountFor($request);

        $withdrawals = AccountWithdrawal::query()
            ->where('business_account_id', $account->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return WithdrawalResource::collection($withdrawals)->additional([
            'statuses' => array_map(
                fn (AccountWithdrawalStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                AccountWithdrawalStatus::cases(),
            ),
        ]);
    }

    /**
     * Withdrawal options
     *
     * What the withdrawal form needs, all decided by the server: the amount that
     * may be withdrawn right now (`available_balance`, never platform credit), the
     * `minimum` and `maximum` per request (`maximum` is null when there is no
     * ceiling), and the active payout methods to pay out to. There is no fee: the
     * amount reserved is exactly the amount requested. Only a person who runs the
     * business may withdraw: `403` otherwise.
     *
     * @response array{available_balance: MoneyResource, minimum: MoneyResource, maximum: MoneyResource|null, currency: string, methods: list<array{id: string, label: string, type: string, type_label: string, masked_number: string, is_default: bool}>}
     */
    public function options(Request $request, AccountWithdrawalLimits $limits): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', AccountWithdrawal::class);

        $currency = Currency::BDT;

        $methods = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('status', PayoutMethodStatus::Active)
            ->orderByDesc('is_default')
            ->get()
            ->map(fn (PayoutMethod $method) => [
                'id' => $method->public_id,
                'label' => $method->label,
                'type' => $method->type->value,
                'type_label' => $method->type->label(),
                'masked_number' => $method->maskedNumber(),
                'is_default' => $method->is_default,
            ])
            ->values()
            ->all();

        return new JsonResponse([
            'available_balance' => new MoneyResource($this->walletFor($account)->availableForWithdrawal()),
            'minimum' => new MoneyResource($limits->minimumFor($account, $currency)),
            'maximum' => MoneyResource::nullable($limits->maximumFor($account, $currency)),
            'currency' => $currency->value,
            'methods' => $methods,
        ]);
    }

    /**
     * Request a withdrawal
     *
     * Asks to withdraw `amount` (a decimal Taka string) to one of the account's
     * active payout methods. The amount is reserved on the wallet in one locked
     * transaction before the request becomes visible; staff then review it.
     * Send an `Idempotency-Key` header (up to 64 characters, one fresh value per
     * submission, reused only when retrying that same submission): a retry with
     * the same key returns the original withdrawal with `200` instead of reserving
     * the money twice, whatever amount or method the retry carries.
     * Errors: `403` unless the caller runs the business; `404` for a payout method
     * that is not this account's active one; `422` on `amount` when it is below the
     * minimum, above the maximum, above the withdrawable balance, when a KYC
     * re-verification blocks new withdrawals, or when the account is not active.
     *
     * @response 201 array{data: WithdrawalDetailResource}
     */
    public function store(RequestWithdrawalRequest $request, RequestAccountWithdrawal $requestWithdrawal): JsonResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', AccountWithdrawal::class);

        $wallet = $this->walletFor($account);

        /** @var PayoutMethod $method */
        $method = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('public_id', $request->validated('payout_method_id'))
            ->where('status', PayoutMethodStatus::Active)
            ->firstOrFail();

        try {
            $withdrawal = $requestWithdrawal->handle(
                $account,
                $wallet,
                $method,
                DecimalAmount::parse($request->validated('amount'), Currency::BDT),
                'account-withdrawal:'.$account->id.':'.$request->validated('idempotency_key'),
            );
        } catch (AccountWithdrawalRefused $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        $withdrawal->load('statusHistory');

        return (new WithdrawalDetailResource($withdrawal))
            ->response()
            ->setStatusCode($withdrawal->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Withdrawal detail
     *
     * One withdrawal with where it is paid and its status history. A withdrawal
     * that is not this account's answers `404`.
     */
    public function show(Request $request, string $withdrawal): WithdrawalDetailResource
    {
        $account = $this->businessAccountFor($request);

        return new WithdrawalDetailResource($this->withdrawalFor($account, $withdrawal)->load('statusHistory'));
    }

    protected function withdrawalFor(BusinessAccount $account, string $publicId): AccountWithdrawal
    {
        return AccountWithdrawal::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    protected function walletFor(BusinessAccount $account): Wallet
    {
        return Wallet::query()
            ->where('business_account_id', $account->id)
            ->firstOrFail();
    }
}
