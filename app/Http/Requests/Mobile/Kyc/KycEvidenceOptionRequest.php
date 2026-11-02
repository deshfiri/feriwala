<?php

namespace App\Http\Requests\Mobile\Kyc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing, or walking away from, one option of a group on a batch round (D30).
 */
class KycEvidenceOptionRequest extends FormRequest
{
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
        ];
    }
}
