<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteCategoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            /** Whether the category is shown on the storefront. */
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
