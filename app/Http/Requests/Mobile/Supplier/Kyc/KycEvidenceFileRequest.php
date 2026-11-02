<?php

namespace App\Http\Requests\Mobile\Supplier\Kyc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycEvidenceFileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The requirement `key`, from `batch.groups[].options[].key`. */
            'requirement' => ['required', 'string', 'max:64'],
            /** The slot `key` within that requirement. */
            'slot' => ['required', 'string', 'max:48'],
            /** The file, as `multipart/form-data`. Up to 10 MB; the slot lists what it accepts. */
            'file' => ['required', 'file', 'max:10240'],
            /** True when a selfie was taken live with the camera. */
            'captured_live' => ['nullable', 'boolean'],
            /** Safe technical facts about a live capture. */
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