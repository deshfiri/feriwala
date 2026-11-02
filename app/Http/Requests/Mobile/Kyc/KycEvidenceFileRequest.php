<?php

namespace App\Http\Requests\Mobile\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A file into one slot of a requirement on a batch round (D30).
 * Sent as `multipart/form-data`.
 */
class KycEvidenceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The requirement's `key`. */
            'requirement' => ['required', 'string', 'max:64'],
            /** The slot's `key` within that requirement. */
            'slot' => ['required', 'string', 'max:48'],
            /** The file, up to 10 MB. Formats and size per slot are refused with a `422` naming the limit. */
            'file' => ['required', 'file', 'max:10240'],
            /** True when the image was taken with the camera just now (the live selfie). */
            'captured_live' => ['nullable', 'boolean'],
            /** Technical facts about a live capture. */
            'capture_meta' => ['nullable', 'array'],
            /** Frame width in pixels. */
            'capture_meta.width' => ['nullable', 'integer', 'min:1', 'max:20000'],
            /** Frame height in pixels. */
            'capture_meta.height' => ['nullable', 'integer', 'min:1', 'max:20000'],
            /** Which camera took it. */
            'capture_meta.facing' => ['nullable', Rule::in(['user', 'environment'])],
        ];
    }
}
