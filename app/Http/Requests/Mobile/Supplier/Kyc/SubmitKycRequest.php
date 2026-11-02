<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class SubmitKycRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Required on a batch round: confirms what was given is the applicant's own and complete. Send `true`. */
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}