<?php

namespace App\Http\Requests\Mobile\Account;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The token from the reset link in the email. */
            'token' => ['required', 'string'],
            /** The account's email address. */
            'email' => ['required', 'string', 'email'],
            /** The new password; the policy is `password_rules` on `GET /register/options`. */
            'password' => $this->passwordRules(),
            /** Must equal `password`. */
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
