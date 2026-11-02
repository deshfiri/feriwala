<?php

namespace App\Http\Requests\Mobile\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The filters every catalogue list takes. The wholesale-only ones (`stock`,
 * `price_min`, `price_max`) are ignored for an account whose package does not
 * include wholesale, exactly as on the web.
 */
class CatalogBrowseRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Matches the product name or SKU. */
            'search' => ['nullable', 'string', 'max:120'],
            /** A category `public_id` from `options.categories`; a parent also matches its subcategories. */
            'category' => ['nullable', 'string', 'max:64'],
            /** A brand `public_id` from `options.brands`. */
            'brand' => ['nullable', 'string', 'max:64'],
            /** Wholesale only: whether the account can order the product now. */
            'stock' => ['nullable', 'string', 'in:in_stock,out_of_stock'],
            /**
             * Wholesale only: lowest wholesale price, exact decimal Taka.
             *
             * @example 100.00
             */
            'price_min' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            /**
             * Wholesale only: highest wholesale price, exact decimal Taka.
             *
             * @example 2500.00
             */
            'price_max' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            /** `mine` keeps only what the account has bought. Ignored on the channel lists. */
            'scope' => ['nullable', 'string', 'in:all,mine'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
