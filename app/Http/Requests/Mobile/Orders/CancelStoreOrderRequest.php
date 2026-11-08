<?php

namespace App\Http\Requests\Mobile\Orders;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling a Store Order that is still Pending (D34).
 */
class CancelStoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Why the order is being cancelled. */
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
