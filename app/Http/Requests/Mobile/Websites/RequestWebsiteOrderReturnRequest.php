<?php

namespace App\Http\Requests\Mobile\Websites;

use App\Domain\Order\Enums\ReturnReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestWebsiteOrderReturnRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** A value from `reasons` on the order endpoint. */
            'reason' => ['required', Rule::in(ReturnReason::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            /**
             * Quantity to return by SKU; a quantity of 0 leaves the line out.
             *
             * @example {"SKU-1": 2}
             */
            'lines' => ['required', 'array'],
            'lines.*' => ['integer', 'min:0'],
            /** A key chosen by the app for this attempt, so a retry is the same request once. */
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }
}
