<?php

namespace App\Http\Requests\Mobile\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The login email address. */
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            /** A label for this phone, shown when the person reviews their signed-in devices. */
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
