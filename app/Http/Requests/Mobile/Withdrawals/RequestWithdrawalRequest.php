<?php

namespace App\Http\Requests\Mobile\Withdrawals;

use App\Support\Money\Currency;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A withdrawal request.
 *
 * The `Idempotency-Key` header is read into `idempotency_key` so it is validated
 * like any other input; it is stable across a retry of the same submission and
 * is what stops a double-tap creating a second withdrawal.
 */
class RequestWithdrawalRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => trim((string) $this->header('Idempotency-Key'))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * The payout method to pay out to: `id` of one of the account's active payout methods.
             *
             * @example "01JABCDEF0123456789ABCDEFG"
             */
            'payout_method_id' => ['required', 'string'],
            /**
             * How much to withdraw, in flat Taka as a decimal string with at most two decimals.
             *
             * @example "600.00"
             */
            'amount' => ['required', new DecimalAmountRule(Currency::BDT)],
            /**
             * Taken from the `Idempotency-Key` header (up to 64 characters), not the body.
             *
             * @ignoreParam
             */
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'An Idempotency-Key header of up to 64 characters is required.',
            'idempotency_key.max' => 'The Idempotency-Key header may not be longer than 64 characters.',
        ];
    }
}
