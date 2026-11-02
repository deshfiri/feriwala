<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use Illuminate\Foundation\Http\FormRequest;

class KycEvidenceOptionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The group `key`, from `batch.groups[].key`. */
            'group' => ['required', 'string', 'max:48'],
            /** The option's requirement `key` within that group. */
            'requirement' => ['required', 'string', 'max:64'],
        ];
    }
}