<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Catalog\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    /**
     * Authorisation is the controller's, through the policy — one lookup, one
     * place.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $existing = is_string($category)
            ? Category::query()->where('public_id', $category)->first()
            : null;

        return [
            'name' => ['required', 'string', 'max:120'],

            /*
             * The public identifier a storefront URL carries (§34.3). Unique
             * because two categories sharing one would be indistinguishable in
             * every link that names it.
             */
            'slug' => [
                'nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(Category::class, 'slug')->ignore($existing?->id),
            ],

            /*
             * The parent is named by its public id, never its database id — a
             * form that accepted a row id would be one an attacker could walk
             * (§34.2). Existence is checked here; depth and cycles are the
             * action's, because they are rules about the tree rather than about
             * this field.
             */
            'parent_id' => [
                'nullable', 'string',
                Rule::exists(Category::class, 'public_id'),
            ],

            'description' => ['nullable', 'string', 'max:5000'],

            /*
             * The category tile partner storefronts render (§11.3).
             *
             * `mimetypes` rather than `mimes`: the first reads the file's own
             * bytes, the second trusts the extension somebody typed. The size
             * and the accepted list are {@see CatalogImageStore}'s, stated here
             * so the person gets a message against the field rather than a
             * refusal after the form closes — the store checks them again,
             * because that is the check a future caller cannot skip.
             */
            'image' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', CatalogImageStore::ACCEPTED_MIME_TYPES),
                'max:'.(int) (CatalogImageStore::MAX_BYTES / 1024),
            ],
            'remove_image' => ['boolean'],

            'image_alt' => ['nullable', 'string', 'max:255'],

            // SEO for partner websites (§11.3, §34.3). Bounded to what a search
            // engine will actually read rather than left open.
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:200'],
            'meta_keywords' => ['nullable', 'string', 'max:255'],

            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'parent_id' => 'parent category',
            'meta_title' => 'SEO title',
            'meta_description' => 'SEO description',
        ];
    }
}
