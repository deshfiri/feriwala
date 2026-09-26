<?php

namespace App\Http\Requests\Cms;

use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Models\Media;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A page's own SEO override (§34). Every field is nullable — a blank field
 * is not an empty string to publish, it is "no override", so the reader's
 * fallback chain reaches the global default instead (see
 * {@see PublishPage::snapshot()}).
 */
class SavePageMetaRequest extends FormRequest
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
            'title.en' => ['nullable', 'string', 'max:255'],
            'title.bn' => ['nullable', 'string', 'max:255'],
            'description.en' => ['nullable', 'string', 'max:500'],
            'description.bn' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'string', 'max:255', 'url'],
            'og_image_id' => ['nullable', 'string', Rule::exists(Media::class, 'public_id')],
            'robots' => ['nullable', 'string', 'max:64'],
        ];
    }
}
