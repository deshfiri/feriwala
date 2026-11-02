<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class SelectWebsiteProductRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Public id of a dropshipping catalogue product the account may sell. */
            'product' => ['required', 'string', 'size:26'],
        ];
    }
}
