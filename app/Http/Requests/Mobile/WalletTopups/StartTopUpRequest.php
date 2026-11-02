<?php

namespace App\Http\Requests\Mobile\WalletTopups;

use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartTopUpRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * How much to add, in flat Taka as a decimal string with at most two decimals.
             *
             * @example "5000.00"
             */
            'amount' => ['required', new DecimalAmountRule],
            /**
             * The payment gateway to pay through, one of `gateways[].value` from the top-up options.
             *
             * @example "sslcommerz"
             */
            'gateway' => ['required', 'string', Rule::in(app(PaymentGatewayManager::class)->availableFor(Currency::base()))],
        ];
    }
}
