<?php

namespace App\Http\Requests\Mobile\Account;

use App\Concerns\PasswordValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The password in use now. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            /** The new password; the policy is `password_rules` on `GET /register/options`. */
            'password' => $this->passwordRules(),
            /** Must equal `password`. */
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
