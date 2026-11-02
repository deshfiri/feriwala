<?php

namespace App\Http\Requests\Mobile\Supplier\PayoutMethods;

use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Payout\Enums\PayoutMethodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating or replacing a payout method. Sensitive, so the password rides along.
 */
class SavePayoutMethodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The supplier's current password; changing payout details is sensitive. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            /** `bank_account`, `bkash` or `nagad`. */
            'type' => ['required', Rule::enum(PayoutMethodType::class)],
            /** A name for the method, shown in lists. */
            'label' => ['required', 'string', 'max:80'],
            /**
             * The fields for the type, as listed under `types[].fields` on `GET /supplier/payout-methods`.
             * A bank account also needs `confirm_account_number`, equal to `account_number`.
             *
             * @example {"account_holder_name": "Rahim Traders", "account_number": "1234567890", "confirm_account_number": "1234567890", "account_type": "current"}
             */
            'details' => ['required', 'array'],
            'details.*' => ['required', 'string', 'max:120'],
            /** `bank_code` from `GET /supplier/banks`. Required for a bank account. */
            'bank_code' => ['required_if:type,bank_account', 'nullable', 'string'],
            /** `routing_number` of a branch from `GET /supplier/banks/{bank_code}/branches`. Required for a bank account. */
            'routing_number' => ['required_if:type,bank_account', 'nullable', 'string'],
            /** Make this the default method. */
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What the save Action takes, after the checks that need more than one field.
     *
     * @return array{type: PayoutMethodType, label: string, details: array<string, mixed>, bd_bank_id: int|null, bd_bank_branch_id: int|null, is_default: bool}
     *
     * @throws ValidationException
     */
    public function payload(): array
    {
        $validated = $this->validated();

        $type = PayoutMethodType::from($validated['type']);
        $rawDetails = $validated['details'];

        if (isset($rawDetails['account_number'], $rawDetails['confirm_account_number'])
            && $rawDetails['account_number'] !== $rawDetails['confirm_account_number']) {
            throw ValidationException::withMessages([
                'details' => __('supplier.payout_methods.account_number_mismatch'),
            ]);
        }

        $allowed = $type->detailFields();
        $details = array_intersect_key($rawDetails, array_flip($allowed));

        $missing = array_filter($allowed, fn (string $field) => trim((string) ($details[$field] ?? '')) === '');

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'details' => __('supplier.payout_methods.missing_fields', ['fields' => implode(', ', $missing)]),
            ]);
        }

        [$bankId, $branchId] = $type->requiresBankBranch()
            ? $this->resolveBankAndBranch($validated['bank_code'], $validated['routing_number'])
            : [null, null];

        return [
            'type' => $type,
            'label' => $validated['label'],
            'details' => $details,
            'bd_bank_id' => $bankId,
            'bd_bank_branch_id' => $branchId,
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
                'bank_code' => __('supplier.payout_methods.invalid_bank_branch'),
            ]);
        }

        return [$bank->id, $branch->id];
    }
}