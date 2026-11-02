<?php

namespace App\Http\Requests\Mobile\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

class PayOrderRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** A different method than the order started with: a `name` from the order's `payment_methods`. */
            'payment_method' => ['nullable', 'string', 'max:32'],
        ];
    }
}
