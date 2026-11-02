<?php

namespace App\Http\Requests\Mobile\Account;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The six-digit code currently shown by the authenticator app. */
            'code' => ['required', 'string', 'digits:6'],
        ];
    }
}
