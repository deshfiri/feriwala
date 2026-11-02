<?php

namespace App\Http\Requests\Mobile\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutSavedAddressRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The `id` of one of the account's saved addresses, from `address_book` on the checkout. */
            'address' => ['required', 'string', 'max:64'],
        ];
    }
}
