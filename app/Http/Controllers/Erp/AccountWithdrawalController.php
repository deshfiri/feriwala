<?php

namespace App\Http\Controllers\Erp;

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
use App\Http\Controllers\Controller;
use App\Http\Controllers\Supplier\WithdrawalController;
use App\Support\Money\Currency;
use App\Support\Money\DecimalAmount;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A Client/Partner `BusinessAccount`'s own withdrawal requests (§27, mirrors
 * {@see WithdrawalController} for a different
 * owner).
 *
 * The server is authoritative throughout: balance, limits and eligibility are
 * all read fresh from {@see RequestAccountWithdrawal} and the wallet, never
 * trusted from the request. The create screen carries a server-generated
 * idempotency key as a hidden field — stable across a retried submission of
 * the *same* form, so a double-click or a network retry reaches
 * {@see RequestAccountWithdrawal}'s own unique-index guard rather than
 * creating a second request.
 */
class AccountWithdrawalController extends Controller
{
    use ResolvesBusinessAccount;

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        $withdrawals = AccountWithdrawal::query()
            ->where('business_account_id', $account->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AccountWithdrawal $withdrawal) => $this->row($withdrawal));

        return Inertia::render('erp/withdrawals/index', [
            'withdrawals' => $withdrawals,
            'statuses' => array_map(
                fn (AccountWithdrawalStatus $case) => ['value' => $case->value, 'label' => $case->label()],
                AccountWithdrawalStatus::cases(),
            ),
        ]);
    }

    public function show(Request $request, string $withdrawal): Response
    {
        $account = $this->businessAccountFor($request);
        $model = $this->withdrawalFor($account, $withdrawal);

        $model->load('statusHistory');

        return Inertia::render('erp/withdrawals/show', [
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

    public function create(Request $request, AccountWithdrawalLimits $limits): Response
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', AccountWithdrawal::class);

        $currency = Currency::BDT;
        $wallet = $this->walletFor($account);

        $methods = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
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

        return Inertia::render('erp/withdrawals/create', [
            'available_balance' => $wallet->availableForWithdrawal()->jsonSerialize(),
            'minimum' => $limits->minimumFor($account, $currency)->jsonSerialize(),
            'maximum' => $limits->maximumFor($account, $currency)?->jsonSerialize(),
            'currency' => $currency->value,
            'methods' => $methods,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, RequestAccountWithdrawal $requestWithdrawal): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', AccountWithdrawal::class);

        $validated = $request->validate([
            'payout_method_id' => ['required', 'string'],
            // Decimal Taka (§36.1) — never minor units at this boundary.
            'amount' => ['required', new DecimalAmountRule(Currency::BDT)],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $wallet = $this->walletFor($account);

        /** @var PayoutMethod $method */
        $method = PayoutMethod::query()
            ->where('owner_type', PayoutOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('public_id', $validated['payout_method_id'])
            ->where('status', PayoutMethodStatus::Active)
            ->firstOrFail();

        try {
            $withdrawal = $requestWithdrawal->handle(
                $account,
                $wallet,
                $method,
                DecimalAmount::parse($validated['amount'], Currency::BDT),
                'account-withdrawal:'.$account->id.':'.$validated['idempotency_key'],
            );
        } catch (AccountWithdrawalRefused $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        return redirect()->route('withdrawals.show', $withdrawal->public_id)
            ->with('success', __('withdrawal.erp.requested'));
    }

    protected function withdrawalFor(BusinessAccount $account, string $publicId): AccountWithdrawal
    {
        /** @var AccountWithdrawal $withdrawal */
        $withdrawal = AccountWithdrawal::query()
            ->where('business_account_id', $account->id)
            ->where('public_id', $publicId)
            ->firstOrFail();

        return $withdrawal;
    }

    protected function walletFor(BusinessAccount $account): Wallet
    {
        /** @var Wallet $wallet */
        $wallet = Wallet::query()
            ->where('business_account_id', $account->id)
            ->firstOrFail();

        return $wallet;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(AccountWithdrawal $withdrawal): array
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
