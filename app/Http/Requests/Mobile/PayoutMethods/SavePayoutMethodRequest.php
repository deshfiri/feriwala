<?php

namespace App\Http\Requests\Mobile\PayoutMethods;

use App\Domain\Bank\Models\BdBank;
use App\Domain\Bank\Models\BdBankBranch;
use App\Domain\Payout\Enums\PayoutMethodType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating or replacing a payout method. Replacing sends the whole method again,
 * exactly as the web form does.
 *
 * Changing where money is paid is sensitive, so the person's password is asked
 * for every time.
 */
class SavePayoutMethodRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** The signed-in person's own password. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            /** One of `types[].value` from the payout-method list. */
            'type' => ['required', Rule::enum(PayoutMethodType::class)],
            /**
             * A name the account holder recognises it by.
             *
             * @example "Primary bKash"
             */
            'label' => ['required', 'string', 'max:80'],
            /**
             * The method's fields, named as `types[].fields` for the chosen type: `account_holder_name`, `account_number`
             * and, for a bank account, `account_type`. Add `confirm_account_number` to have the server check it matches.
             *
             * @example {"account_holder_name": "Karim Traders", "account_number": "01711112222"}
             */
            'details' => ['required', 'array'],
            'details.*' => ['required', 'string', 'max:120'],
            /** Bank account only: `bank_code` from the banks lookup. */
            'bank_code' => ['required_if:type,bank_account', 'nullable', 'string'],
            /** Bank account only: `routing_number` from the bank's branches lookup. */
            'routing_number' => ['required_if:type,bank_account', 'nullable', 'string'],
            /** Make this the default method. */
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated input, checked the way the web form checks it and shaped for the save action.
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
     *
     * @throws ValidationException
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
}
