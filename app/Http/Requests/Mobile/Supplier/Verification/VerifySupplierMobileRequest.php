<?php

namespace App\Http\Requests\Mobile\Supplier\Verification;

use Illuminate\Foundation\Http\FormRequest;

class VerifySupplierMobileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The code sent by SMS to the supplier's mobile number. */
            'code' => ['required', 'string'],
        ];
    }
}