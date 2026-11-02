<?php

namespace App\Http\Requests\Mobile\Websites;

use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteProductRequest extends FormRequest
{
    /**
     * Every key is optional: only what is sent changes.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * Selling price in flat Taka, as a decimal string. Checked against the product's bounds
             * on the server.
             *
             * @example 2500.00
             */
            'price' => ['sometimes', new DecimalAmountRule],
            /**
             * Promotional price in flat Taka, as a decimal string; `null` clears it.
             *
             * @example 2200.50
             */
            'promotional_price' => ['sometimes', 'nullable', new DecimalAmountRule],
            /** When the promotion starts, an ISO 8601 instant with its offset. */
            'promotion_starts_at' => ['sometimes', 'nullable', 'date'],
            /** When the promotion ends, an ISO 8601 instant with its offset. */
            'promotion_ends_at' => ['sometimes', 'nullable', 'date'],
            'promo_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'marketing_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            /** Public id of one of the storefront's categories; `null` removes it from its category. */
            'website_category_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_featured' => ['sometimes', 'boolean'],
        ];
    }
}
