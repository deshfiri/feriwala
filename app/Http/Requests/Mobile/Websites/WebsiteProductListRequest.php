<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class WebsiteProductListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Matches the product's name or SKU. */
            'search' => ['nullable', 'string', 'max:120'],
            /** A value from `statuses` on this endpoint. */
            'status' => ['nullable', 'string', 'max:40'],
            /** Public id of one of the storefront's categories. */
            'category' => ['nullable', 'string', 'size:26'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
