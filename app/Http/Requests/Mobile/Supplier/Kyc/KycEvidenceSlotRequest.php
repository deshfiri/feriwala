<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use App\Http\Requests\Mobile\Kyc\Concerns\AcceptsDocumentType;
use Illuminate\Foundation\Http\FormRequest;

class KycEvidenceSlotRequest extends FormRequest
{
    use AcceptsDocumentType;

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The requirement `key`. */
            'requirement' => ['required', 'string', 'max:64'],
            /** The same requirement `key`, under the name the flat list uses. Send this or `requirement`. */
            'document_type' => ['nullable', 'string', 'max:64'],
            /** The slot `key` within that requirement. */
            'slot' => ['required', 'string', 'max:48'],
        ];
    }
}
