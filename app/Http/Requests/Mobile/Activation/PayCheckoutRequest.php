<?php

namespace App\Http\Requests\Mobile\Activation;

use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying for the activation (§9).
 */
class PayCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** A gateway `name` from the checkout's `gateways`. @example sslcommerz */
            'gateway' => ['required', 'string', Rule::in(app(PaymentGatewayManager::class)->availableFor(Currency::base()))],
            /** A coupon code to apply. The server re-validates it and the quote is recalculated; nothing sent here sets a price. */
            'coupon' => ['nullable', 'string', 'max:40'],
        ];
    }
}
