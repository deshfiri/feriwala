<?php

namespace App\Http\Requests\Mobile\Kyc;

use App\Http\Requests\Mobile\Kyc\Concerns\AcceptsDocumentType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing, or walking away from, one option of a group on a batch round (D30).
 */
class KycEvidenceOptionRequest extends FormRequest
{
    use AcceptsDocumentType;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The group's `key`. */
            'group' => ['required', 'string', 'max:48'],
            /** The requirement's `key` within that group. */
            'requirement' => ['required', 'string', 'max:64'],
            /** The same requirement `key`, under the name the flat list uses. Send this or `requirement`. */
            'document_type' => ['nullable', 'string', 'max:64'],
        ];
    }
}
