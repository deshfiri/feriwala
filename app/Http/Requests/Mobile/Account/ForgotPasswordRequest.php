<?php

namespace App\Http\Requests\Mobile\Account;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The account's email address. */
            'email' => ['required', 'string', 'email'],
        ];
    }
}
