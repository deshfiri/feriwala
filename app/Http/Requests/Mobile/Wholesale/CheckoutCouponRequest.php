<?php

namespace App\Http\Requests\Mobile\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutCouponRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The coupon code as typed. The discount is worked out by the server. */
            'code' => ['required', 'string', 'max:64'],
        ];
    }
}
