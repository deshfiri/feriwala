<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycEvidenceValueRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The requirement `key`. */
            'requirement' => ['required', 'string', 'max:64'],
            /** The slot `key` within that requirement. */
            'slot' => ['required', 'string', 'max:48'],
            /** The typed value, e.g. an ID number. Stored encrypted and shown masked. */
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}