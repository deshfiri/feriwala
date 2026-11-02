<?php

namespace App\Http\Requests\Mobile\Packages;

use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving to another package (§8.3).
 */
class ChangePackageRequest extends FormRequest
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
            /** The `slug` of the package to move to, from `GET /subscription/change`. */
            'package' => ['required', 'string'],
            /** A gateway `name` from the options' `gateways`. @example sslcommerz */
            'gateway' => ['required', 'string', Rule::in(app(PaymentGatewayManager::class)->availableFor(Currency::base()))],
        ];
    }
}
