<?php

namespace App\Http\Requests\Mobile\Kyc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One document or value for a round on the earlier flat KYC list.
 * Sent as `multipart/form-data` when it carries a file.
 */
class StoreKycDocumentRequest extends FormRequest
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
            /** The requirement's `key` from the form. @example national_id */
            'document_type' => ['required', 'string'],
            /** The file, up to 10 MB. The accepted formats are on the requirement. */
            'file' => ['nullable', 'file', 'max:10240'],
            /** A typed value, for a requirement that asks for one (e.g. an ID number). */
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
