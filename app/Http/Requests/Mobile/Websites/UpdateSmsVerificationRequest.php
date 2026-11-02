<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsVerificationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Whether cash-on-delivery customers confirm with an SMS code at checkout. */
            'enabled' => ['required', 'boolean'],
        ];
    }
}
