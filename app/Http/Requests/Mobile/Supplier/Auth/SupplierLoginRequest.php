<?php

namespace App\Http\Requests\Mobile\Supplier\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SupplierLoginRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The supplier login email address. */
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            /** A label for this phone, shown in the signed-in devices list. */
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
