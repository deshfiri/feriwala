<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\SupplierWithdrawalLimits;
use App\Http\Controllers\Controller;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Supplier's own withdrawal requests (D25, P13-24).
 *
 * The server is authoritative throughout: balance, limits and eligibility are
 * all read fresh from {@see RequestSupplierWithdrawal} and the wallet, never
 * trusted from the request. The create screen carries a server-generated
 * idempotency key as a hidden field — stable across a retried submission of
 * the *same* form, so a double-click or a network retry reaches
 * {@see RequestSupplierWithdrawal}'s own unique-index guard rather than
 * creating a second request.
 */
class WithdrawalController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $withdrawals = SupplierWithdrawal::query()
            ->where('supplier_id', $supplier->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (SupplierWithdrawal $withdrawal) => $this->row($withdrawal));

        return Inertia::render('supplier/withdrawals/index', [
            'withdrawals' => $withdrawals,
            'statuses' => array_map(
                fn (SupplierWithdrawalStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                SupplierWithdrawalStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, string $withdrawal): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        /** @var SupplierWithdrawal $model */
        $model = SupplierWithdrawal::query()
            ->where('supplier_id', $supplier->id)
            ->where('public_id', $withdrawal)
            ->with('statusHistory')
            ->firstOrFail();

        return Inertia::render('supplier/withdrawals/show', [
            'withdrawal' => [
                ...$this->row($model),
                'payout_snapshot' => $model->payout_snapshot,
                'failure_reason' => $model->failure_reason,
                'external_reference' => $model->external_reference,
                'history' => $model->statusHistory->map(fn ($change) => [
                    'previous_status' => $change->previous_status?->label(),
                    'new_status' => $change->new_status->label(),
                    'reason' => $change->reason,
                    'changed_at' => $change->changed_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    public function create(Request $request, SupplierWithdrawalLimits $limits, OpenSupplierWallet $openWallet): Response
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $currency = Currency::BDT;
        $wallet = $openWallet->handle($supplier, $currency);

        $methods = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::Supplier->value)
            ->where('owner_id', $supplier->id)
            ->where('status', PayoutMethodStatus::Active)
            ->orderByDesc('is_default')
            ->get()
            ->map(fn (PayoutMethod $method) => [
                'id' => $method->public_id,
                'label' => $method->label,
                'type_label' => $method->type->label(),
                'masked_number' => $method->maskedNumber(),
                'is_default' => $method->is_default,
            ]);

        return Inertia::render('supplier/withdrawals/create', [
            'available_balance' => $wallet->availableBalance()->jsonSerialize(),
            'minimum' => $limits->minimumFor($supplier, $currency)->jsonSerialize(),
            'maximum' => $limits->maximumFor($supplier, $currency)?->jsonSerialize(),
            'currency' => $currency->value,
            'methods' => $methods,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, RequestSupplierWithdrawal $requestWithdrawal): RedirectResponse
    {
        /** @var Supplier $supplier */
        $supplier = $request->user('supplier');

        $validated = $request->validate([
            'payout_method_id' => ['required', 'string'],
            // Decimal Taka (§36.1) — never minor units at this boundary.
            'amount' => ['required', new DecimalAmountRule(Currency::BDT)],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        /** @var PayoutMethod $method */
        $method = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::Supplier->value)
            ->where('owner_id', $supplier->id)
            ->where('public_id', $validated['payout_method_id'])
            ->where('status', PayoutMethodStatus::Active)
            ->firstOrFail();

        try {
            $withdrawal = $requestWithdrawal->handle(
                $supplier,
                $method,
                DecimalAmount::parse($validated['amount'], Currency::BDT),
                'supplier-withdrawal:'.$supplier->id.':'.$validated['idempotency_key'],
            );
        } catch (SupplierWithdrawalRefused|SupplierWalletOperationRefused $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('supplier.withdrawals.show', $withdrawal->public_id)
            ->with('success', __('supplier.withdrawals.requested'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(SupplierWithdrawal $withdrawal): array
    {
        return [
            'id' => $withdrawal->public_id,
            'reference' => $withdrawal->reference,
            'amount' => $withdrawal->amount->jsonSerialize(),
            'status' => $withdrawal->status->value,
            'status_label' => $withdrawal->status->label(),
            'status_tone' => $withdrawal->status->tone(),
            'payout_snapshot' => [
                'type_label' => $withdrawal->payout_snapshot['type_label'] ?? null,
                'label' => $withdrawal->payout_snapshot['label'] ?? null,
                'masked_number' => $withdrawal->payout_snapshot['masked_number'] ?? null,
            ],
            'requested_at' => $withdrawal->requested_at->toIso8601String(),
            'decided_at' => $withdrawal->decided_at?->toIso8601String(),
            'paid_at' => $withdrawal->paid_at?->toIso8601String(),
        ];
    }
}
