<?php

namespace App\Http\Requests\Mobile\Supplier\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierProfileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The person Feriwala deals with. */
            'contact_person_name' => ['required', 'string', 'max:255'],
            /** Registered business address, free text. */
            'business_address' => ['required', 'string', 'max:1000'],
            /** Preferred language, `en` or `bn`. */
            'locale' => ['required', 'in:en,bn'],
        ];
    }
}