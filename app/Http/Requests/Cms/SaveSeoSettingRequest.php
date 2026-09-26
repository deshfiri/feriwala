<?php

namespace App\Http\Requests\Cms;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The global, per-locale SEO defaults every page's own override falls back
 * to (§34).
 */
class SaveSeoSettingRequest extends FormRequest
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
            'default_title' => ['required', 'string', 'max:255'],
            'default_description' => ['nullable', 'string', 'max:500'],
            'default_og_image_path' => ['nullable', 'string', 'max:255'],
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_logo_path' => ['nullable', 'string', 'max:255'],
            'organization_url' => ['nullable', 'string', 'max:255', 'url'],
            'robots_default' => ['required', 'string', 'max:64'],
            'twitter_handle' => ['nullable', 'string', 'max:32'],
        ];
    }
}
