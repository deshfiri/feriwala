<?php

namespace App\Http\Requests\Mobile\Packages;

use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Paying for a renewal (§8.2).
 */
class PayRenewalRequest extends FormRequest
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
            /** A gateway `name` from the renewal's `gateways`. @example sslcommerz */
            'gateway' => ['required', 'string', Rule::in(app(PaymentGatewayManager::class)->availableFor(Currency::base()))],
        ];
    }
}
