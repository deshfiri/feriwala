<?php

namespace App\Http\Requests\Cms;

use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Models\Page;
use App\Domain\Cms\Models\PageSection;
use App\Domain\Cms\Support\SectionContentValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The scalar fields a section's own row holds. `content`'s shape depends on
 * `kind` and is checked by
 * {@see SectionContentValidator} inside the action,
 * not here — this only checks that something array-shaped was sent at all.
 *
 * `section_key` is only asked when creating a new section: a page may carry
 * more than one section of the same kind (two `cta` blocks, say), each its
 * own row and its own key, so the key — not the kind — is what identifies
 * one uniquely. Editing an existing section addresses it by route
 * parameter instead.
 */
class SaveSectionRequest extends FormRequest
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
        /** @var Page $page */
        $page = $this->route('page');

        $rules = [
            'kind' => ['required', 'string', Rule::enum(SectionKind::class)],
            'is_enabled' => ['boolean'],
            'visible_on_desktop' => ['boolean'],
            'visible_on_mobile' => ['boolean'],
            'variant' => ['nullable', 'string', 'max:64'],
            'content' => ['required', 'array'],
        ];

        if ($this->route('section') === null) {
            $rules['section_key'] = [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(PageSection::class, 'section_key')->where('cms_page_id', $page->id),
            ];
        }

        return $rules;
    }
}
