<?php

namespace App\Http\Requests\Mobile\Kyc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A typed value into one slot of a requirement on a batch round (D30).
 */
class KycEvidenceValueRequest extends FormRequest
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
            /** The value, e.g. an ID number. */
            'value' => ['required', 'string', 'max:255'],
        ];
    }
}
