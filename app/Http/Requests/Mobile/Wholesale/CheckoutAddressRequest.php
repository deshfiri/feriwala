<?php

namespace App\Http\Requests\Mobile\Wholesale;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The billing or shipping address typed at checkout (P4-7). The shipping address
 * may instead copy the billing one as it stands, in which case no field is read.
 */
class CheckoutAddressRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $copies = $this->copiesBilling();
        $required = $copies ? 'nullable' : 'required';

        return [
            /** Shipping only: use the billing address exactly as it stands. */
            'same_as_billing' => ['nullable', 'boolean'],
            /** Who receives the goods. */
            'contact_name' => [$required, 'string', 'max:255'],
            /**
             * A phone number, 6 to 19 digits, optional leading `+`.
             *
             * @example 01712345678
             */
            'contact_mobile' => [$required, 'string', 'max:20', 'regex:/^\+?[0-9][0-9 -]{5,18}$/'],
            'line_1' => [$required, 'string', 'max:255'],
            'line_2' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'city' => [$required, 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:16'],
        ];
    }

    public function copiesBilling(): bool
    {
        return $this->route('type') === 'shipping' && $this->boolean('same_as_billing');
    }
}
