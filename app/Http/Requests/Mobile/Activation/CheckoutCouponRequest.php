<?php

namespace App\Http\Requests\Mobile\Activation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Trying a coupon code against the checkout (§9).
 */
class CheckoutCouponRequest extends FormRequest
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
            /** The code to try. Empty works out the checkout without one. */
            'code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
