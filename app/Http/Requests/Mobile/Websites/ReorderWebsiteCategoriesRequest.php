<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class ReorderWebsiteCategoriesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The whole arrangement: every category's public id, in the order wanted. */
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string', 'size:26'],
        ];
    }
}
