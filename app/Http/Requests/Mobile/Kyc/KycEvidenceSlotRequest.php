<?php

namespace App\Http\Requests\Mobile\Kyc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking back what one slot holds on a batch round (D30).
 */
class KycEvidenceSlotRequest extends FormRequest
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
            /** The requirement's `key`. */
            'requirement' => ['required', 'string', 'max:64'],
            /** The slot's `key` within that requirement. */
            'slot' => ['required', 'string', 'max:48'],
        ];
    }
}
