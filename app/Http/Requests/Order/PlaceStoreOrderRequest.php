<?php

namespace App\Http\Requests\Order;

use App\Support\Money\Rules\DecimalAmountRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A Partner taking an order for their own customer. Shape only: the website is
 * found inside the Partner's own account, the delivery place is checked against
 * the location directory, and every price is the ERP's.
 */
class PlaceStoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'storefront' => ['required', 'string', 'max:40'],
            'token' => ['required', 'uuid'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:32'],
            'customer_email' => ['nullable', 'email', 'max:254'],
            'division_id' => ['required', 'integer'],
            'district_id' => ['required', 'integer'],
            'upazila_id' => ['required', 'integer'],
            'union_id' => ['nullable', 'integer'],
            'line1' => ['required', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:1000'],
            'discount' => ['nullable', new DecimalAmountRule],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
