<?php

namespace App\Http\Requests\Mobile\Supplier\Password;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SupplierResetPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The token from the reset link in the email. */
            'token' => ['required', 'string'],
            /** The supplier's login email address. */
            'email' => ['required', 'email'],
            /** The new password. */
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            /** Must equal `password`. */
            'password_confirmation' => ['required', 'string'],
        ];
    }
}