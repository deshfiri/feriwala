<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use App\Http\Requests\Mobile\Kyc\Concerns\AcceptsDocumentType;
use Illuminate\Foundation\Http\FormRequest;

class KycEvidenceOptionRequest extends FormRequest
{
    use AcceptsDocumentType;

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
            /** The same requirement `key`, under the name the flat list uses. Send this or `requirement`. */
            'document_type' => ['nullable', 'string', 'max:64'],
        ];
    }
}
