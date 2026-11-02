<?php

namespace App\Http\Requests\Mobile\Supplier\Fulfilment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceFulfilmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** One of the commitment's `available_actions`. `decline` cancels it. */
            'action' => ['required', Rule::in(['confirm', 'decline', 'start_preparing', 'mark_ready'])],
            /** Why the commitment is declined. Required for `decline`. */
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:action,decline'],
            /** When the goods will be ready, for `confirm`. Must be in the future. Asked for only when `needs_expected_ready_at` is true. */
            'expected_ready_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
