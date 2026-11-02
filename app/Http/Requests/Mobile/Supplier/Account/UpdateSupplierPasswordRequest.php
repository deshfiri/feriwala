<?php

namespace App\Http\Requests\Mobile\Supplier\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateSupplierPasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The password in use now. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            /** The new password. */
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            /** Must equal `password`. */
            'password_confirmation' => ['required', 'string'],
        ];
    }
}