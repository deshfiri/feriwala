<?php

namespace App\Http\Requests\Mobile\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmCheckoutRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** A `name` from `payment_methods` on the checkout: a gateway, or `wallet`. */
            'payment_method' => ['required', 'string', 'max:32'],
            /** The `fingerprint` of the summary the person saw. Any change to the cart, prices, coupon or addresses makes it stale. */
            'fingerprint' => ['required', 'string', 'size:64'],
        ];
    }
}
