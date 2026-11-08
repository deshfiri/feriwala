<?php

namespace App\Http\Controllers\Api\Mobile\V1\Supplier\Withdrawals;

use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Controllers\Api\Mobile\V1\Supplier\ResolvesSupplier;
use App\Http\Requests\Mobile\Supplier\Withdrawals\RequestSupplierWithdrawalRequest;
use App\Http\Requests\Mobile\Supplier\Withdrawals\SupplierWithdrawalListRequest;
use App\Http\Resources\Mobile\MoneyResource;
use App\Http\Resources\Mobile\Supplier\Withdrawals\SupplierWithdrawalDetailResource;
use App\Http\Resources\Mobile\Supplier\Withdrawals\SupplierWithdrawalResource;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * A Supplier's own withdrawal requests (D25, P13-24).
 *
 * Self-scoped by query: another Supplier's withdrawal or payout method is a
 * `404`. The server is authoritative for balance, limits and eligibility. The
 * reservation itself is {@see RequestSupplierWithdrawal}: one locked
 * transaction that writes the withdrawal, its ledger entry and its history.
 */
class WithdrawalController extends MobileController
{
    use ResolvesSupplier;

    /**
     * List withdrawals
     *
     * The supplier's withdrawal requests, newest first, 20 to a page, optionally
     * filtered by `status`. `statuses` lists the values the filter accepts.
     *
     * @response array{data: list<SupplierWithdrawalResource>, links: array<string, string|null>, meta: array<string, mixed>, statuses: list<array{value: string, label: string}>}
     */
    public function index(SupplierWithdrawalListRequest $request): AnonymousResourceCollection
    {
        $withdrawals = SupplierWithdrawal::query()
            ->where('supplier_id', $this->supplierFor($request)->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return SupplierWithdrawalResource::collection($withdrawals)->additional([
            'statuses' => array_map(
                fn (SupplierWithdrawalStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                SupplierWithdrawalStatus::cases(),
            ),
        ]);
    }

    /**
     * Withdrawal options
     *
     * What the withdrawal form needs, all decided by the server: the amount that
     * may be withdrawn right now (`available_balance`, never platform credit),
     * the `minimum` and `maximum` per request (`maximum` is null when there is no
     * ceiling), and the active payout methods to pay out to. There is no fee: the
     * amount reserved is exactly the amount requested.
     *
     * @response array{available_balance: MoneyResource, minimum: MoneyResource, maximum: MoneyResource|null, currency: string, methods: list<array{id: string, label: string, type: string, type_label: string, masked_number: string, is_default: bool}>}
     */
    public function options(Request $request, SupplierWithdrawalLimits $limits, OpenSupplierWallet $openWallet): JsonResponse
    {
        $supplier = $this->supplierFor($request);
        $currency = Currency::BDT;

        $methods = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::Supplier->value)
            ->where('owner_id', $supplier->id)
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
            'available_balance' => new MoneyResource($openWallet->handle($supplier, $currency)->availableForWithdrawal()),
            'minimum' => new MoneyResource($limits->minimumFor($supplier, $currency)),
            'maximum' => MoneyResource::nullable($limits->maximumFor($supplier, $currency)),
            'currency' => $currency->value,
            'methods' => $methods,
        ]);
    }

    /**
     * Request a withdrawal
     *
     * Asks to withdraw `amount` (a decimal Taka string) to one of the supplier's
     * active payout methods. The amount is reserved on the wallet in one locked
     * transaction before the request becomes visible; staff then review it.
     * Send an `Idempotency-Key` header (up to 64 characters, one fresh value per
     * submission, reused only when retrying that same submission): a retry with
     * the same key returns the original withdrawal with `200` instead of reserving
     * the money twice.
     * Errors: `404` for a payout method that is not this supplier's active one;
     * `422` on `amount` when it is below the minimum, above the maximum or above
     * the withdrawable balance.
     *
     * @response 201 array{data: SupplierWithdrawalDetailResource}
     */
    public function store(RequestSupplierWithdrawalRequest $request, RequestSupplierWithdrawal $requestWithdrawal): JsonResponse
    {
        $supplier = $this->supplierFor($request);

        /** @var PayoutMethod $method */
        $method = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::Supplier->value)
            ->where('owner_id', $supplier->id)
            ->where('public_id', $request->validated('payout_method_id'))
            ->where('status', PayoutMethodStatus::Active)
            ->firstOrFail();

        try {
            $withdrawal = $requestWithdrawal->handle(
                $supplier,
                $method,
                DecimalAmount::parse($request->validated('amount'), Currency::BDT),
                'supplier-withdrawal:'.$supplier->id.':'.$request->validated('idempotency_key'),
            );
        } catch (SupplierWithdrawalRefused|SupplierWalletOperationRefused $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        $withdrawal->load('statusHistory');

        return (new SupplierWithdrawalDetailResource($withdrawal))
            ->response()
            ->setStatusCode($withdrawal->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Withdrawal detail
     *
     * One withdrawal with where it is paid and its status history. `404` for
     * another supplier's withdrawal.
     */
    public function show(Request $request, string $withdrawal): SupplierWithdrawalDetailResource
    {
        $model = SupplierWithdrawal::query()
            ->where('supplier_id', $this->supplierFor($request)->id)
            ->where('public_id', $withdrawal)
            ->with('statusHistory')
            ->firstOrFail();

        return new SupplierWithdrawalDetailResource($model);
    }
}
