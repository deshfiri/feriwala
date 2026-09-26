<?php

namespace App\Http\Requests\Cms;

use App\Domain\Cms\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The global, per-locale SEO defaults every page's own override falls back
 * to (§34). The Open Graph image and organization logo are a CMS media
 * `public_id` (Stage 7 addendum), never a storage path.
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
            'default_og_image_id' => ['nullable', 'string', Rule::exists(Media::class, 'public_id')],
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_logo_id' => ['nullable', 'string', Rule::exists(Media::class, 'public_id')],
            'organization_url' => ['nullable', 'string', 'max:255', 'url'],
            'robots_default' => ['required', 'string', 'max:64'],
            'twitter_handle' => ['nullable', 'string', 'max:32'],
        ];
    }
}
