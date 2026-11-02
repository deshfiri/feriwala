<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class PublishWebsiteProductRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** `true` to put the product on sale, `false` to take it off. */
            'published' => ['required', 'boolean'],
        ];
    }
}
