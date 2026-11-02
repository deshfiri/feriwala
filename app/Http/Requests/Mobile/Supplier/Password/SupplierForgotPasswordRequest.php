<?php

namespace App\Http\Requests\Mobile\Supplier\Password;

use Illuminate\Foundation\Http\FormRequest;

class SupplierForgotPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The supplier's login email address. */
            'email' => ['required', 'email'],
        ];
    }
}