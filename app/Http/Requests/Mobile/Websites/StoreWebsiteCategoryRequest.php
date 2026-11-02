<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebsiteCategoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The category's name. */
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ];
    }
}
