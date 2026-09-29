<?php

namespace App\Http\Controllers\Erp;

use App\Concerns\ResolvesBusinessAccount;
use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Payout\Actions\ArchivePayoutMethod;
use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Actions\SetDefaultPayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodStatus;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Exceptions\PayoutMethodRefused;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Payout\Queries\PayoutMethodsForOwner;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A `BusinessAccount`'s own payout methods (D25, P13-24; Shared Payout
 * Methods batch).
 *
 * Self-scoped through the signed-in person's own account, the same as
 * {@see AddressController}. Never returns an unmasked account number:
 * {@see PayoutMethod} hides `details` unconditionally, and every response
 * here is built from {@see PayoutMethod::maskedNumber()}.
 */
class PayoutMethodController extends Controller
{
    use ResolvesBusinessAccount;

    public function __construct(
        protected PayoutMethodsForOwner $methods,
        protected SavePayoutMethod $save,
        protected SetDefaultPayoutMethod $setDefault,
        protected ArchivePayoutMethod $archive,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->businessAccountFor($request);

        return Inertia::render('erp/payout-methods/index', [
            'methods' => $this->methods->forOwner(PayoutOwnerType::BusinessAccount, $account->id)
                ->map(fn (PayoutMethod $method) => $this->row($method))
                ->values(),
            'types' => $this->typeOptions(),
            'can' => [
                'create' => $request->user()?->can('create', PayoutMethod::class) ?? false,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->businessAccountFor($request);

        Gate::authorize('create', PayoutMethod::class);

        $validated = $this->validated($request);

        try {
            $this->save->handle(
                ownerType: PayoutOwnerType::BusinessAccount,
                ownerId: $account->id,
                type: $validated['type'],
                label: $validated['label'],
                details: $validated['details'],
                bdBankId: $validated['bd_bank_id'],
                bdBankBranchId: $validated['bd_bank_branch_id'],
                makeDefault: $validated['is_default'],
            );
        } catch (PayoutMethodRefused $refused) {
            throw ValidationException::withMessages(['details' => $refused->getMessage()]);
        }

        return back()->with('success', __('payout.flash.created'));
    }

    public function update(Request $request, string $method): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->methodFor($account->id, $method);

        Gate::authorize('update', $existing);

        $validated = $this->validated($request);

        try {
            $this->save->handle(
                ownerType: PayoutOwnerType::BusinessAccount,
                ownerId: $account->id,
                type: $validated['type'],
                label: $validated['label'],
                details: $validated['details'],
                bdBankId: $validated['bd_bank_id'],
                bdBankBranchId: $validated['bd_bank_branch_id'],
                makeDefault: $validated['is_default'],
                existing: $existing,
            );
        } catch (PayoutMethodRefused $refused) {
            throw ValidationException::withMessages(['details' => $refused->getMessage()]);
        }

        return back()->with('success', __('payout.flash.updated'));
    }

    public function setDefault(Request $request, string $method): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->methodFor($account->id, $method);

        Gate::authorize('setDefault', $existing);

        $this->setDefault->handle($existing);

        return back()->with('success', __('payout.flash.default_set'));
    }

    public function archive(Request $request, string $method): RedirectResponse
    {
        $account = $this->businessAccountFor($request);
        $existing = $this->methodFor($account->id, $method);

        Gate::authorize('archive', $existing);

        $request->validate(['current_password' => ['required', 'string', 'current_password']]);

        $this->archive->handle($existing);

        return back()->with('success', __('payout.flash.archived'));
    }

    protected function methodFor(int $accountId, string $publicId): PayoutMethod
    {
        $method = $this->methods->findForOwner(PayoutOwnerType::BusinessAccount, $accountId, $publicId);

        abort_if($method === null, 404);

        return $method;
    }

    /**
     * @return array{type: PayoutMethodType, label: string, details: array<string, mixed>, bd_bank_id: int|null, bd_bank_branch_id: int|null, is_default: bool}
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'type' => ['required', Rule::enum(PayoutMethodType::class)],
            'label' => ['required', 'string', 'max:80'],
            'details' => ['required', 'array'],
            'details.*' => ['required', 'string', 'max:120'],
            'bank_code' => ['required_if:type,bank_account', 'nullable', 'string'],
            'routing_number' => ['required_if:type,bank_account', 'nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $type = PayoutMethodType::from($validated['type']);
        $rawDetails = $validated['details'];

        if (isset($rawDetails['account_number'], $rawDetails['confirm_account_number'])
            && $rawDetails['account_number'] !== $rawDetails['confirm_account_number']) {
            throw ValidationException::withMessages([
                'details' => __('payout.form.account_number_mismatch'),
            ]);
        }

        $allowed = $type->detailFields();
        $details = array_intersect_key($rawDetails, array_flip($allowed));

        $missing = array_filter($allowed, fn (string $field) => trim((string) ($details[$field] ?? '')) === '');

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'details' => __('payout.form.missing_fields', ['fields' => implode(', ', $missing)]),
            ]);
        }

        [$bdBankId, $bdBankBranchId] = $type->requiresBankBranch()
            ? $this->resolveBankAndBranch($validated['bank_code'], $validated['routing_number'])
            : [null, null];

        return [
            'type' => $type,
            'label' => $validated['label'],
            'details' => $details,
            'bd_bank_id' => $bdBankId,
            'bd_bank_branch_id' => $bdBankBranchId,
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function resolveBankAndBranch(string $bankCode, string $routingNumber): array
    {
        $bank = BdBank::query()->where('bank_code', $bankCode)->where('is_active', true)->first();
        $branch = BdBankBranch::query()->where('routing_number', $routingNumber)->where('is_active', true)->first();

        if ($bank === null || $branch === null || $branch->bank_id !== $bank->id) {
            throw ValidationException::withMessages([
                'bank_code' => __('payout.form.invalid_bank_branch'),
            ]);
        }

        return [$bank->id, $branch->id];
    }

    /**
     * @return list<array{value: string, label: string, fields: array<int, string>}>
     */
    protected function typeOptions(): array
    {
        return array_map(
            fn (PayoutMethodType $case) => ['value' => $case->value, 'label' => $case->label(), 'fields' => $case->detailFields()],
            PayoutMethodType::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(PayoutMethod $method): array
    {
        return [
            'id' => $method->public_id,
            'type' => $method->type->value,
            'type_label' => $method->type->label(),
            'label' => $method->label,
            'masked_number' => $method->maskedNumber(),
            'bank_name' => $method->bank?->name,
            'branch_name' => $method->branch?->name,
            'is_default' => $method->is_default,
            'is_active' => $method->status === PayoutMethodStatus::Active,
            'status_label' => $method->status->label(),
            'verified_at' => $method->verified_at?->toIso8601String(),
            'created_at' => $method->created_at->toIso8601String(),
        ];
    }
}
