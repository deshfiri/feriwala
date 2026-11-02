<?php

namespace App\Http\Requests\Mobile\Catalog;

use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;

class MyProductWebsiteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** `true` puts the product on sale on this storefront, `false` takes it off. */
            'published' => ['required', 'boolean'],
            /**
             * The partner's own selling price, exact decimal Taka. Checked against the administrator's range.
             *
             * @example 1250.00
             */
            'price' => ['nullable', new DecimalAmountRule],
        ];
    }
}
