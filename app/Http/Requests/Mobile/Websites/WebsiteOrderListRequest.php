<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class WebsiteOrderListRequest extends FormRequest
{
    /**
     * Unknown statuses are ignored, as on the web list.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Matches the order reference, the storefront's reference or the customer's name. */
            'search' => ['nullable', 'string', 'max:120'],
            /** An order status, from `statuses` on this endpoint. */
            'status' => ['nullable', 'string', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
