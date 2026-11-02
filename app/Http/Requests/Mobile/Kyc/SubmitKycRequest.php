<?php

namespace App\Http\Requests\Mobile\Kyc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Sending the completed round for review.
 */
class SubmitKycRequest extends FormRequest
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
            /** Required (`true`) for a round on a batch version: the applicant confirms what they gave is theirs and complete. */
            'confirm' => ['nullable', 'boolean'],
        ];
    }
}
