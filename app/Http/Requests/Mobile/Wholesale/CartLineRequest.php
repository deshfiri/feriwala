<?php

namespace App\Http\Requests\Mobile\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting a cart line. A line is set to a quantity, never added to, so a repeated
 * request leaves the same cart. Nothing priced by the client is read.
 */
class CartLineRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            /** The new quantity of the line. Checked against the product's minimum, maximum and the stock this account can order. */
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];

        // Updating names the line in the URL; adding names the product.
        if ($this->route('item') !== null) {
            return $rules;
        }

        return [
            /** The product's `slug` from the wholesale catalogue. */
            'product' => ['required', 'string', 'max:255'],
            /** The variation's SKU. Required in practice for a product with variations. */
            'variant' => ['nullable', 'string', 'max:64'],
            ...$rules,
        ];
    }
}
