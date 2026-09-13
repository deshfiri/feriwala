<?php

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\CatalogImageStore;
use App\Domain\Catalog\Models\Brand;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBrandRequest extends FormRequest
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
        $brand = $this->route('brand');
        $existing = is_string($brand)
            ? Brand::query()->where('public_id', $brand)->first()
            : null;

        return [
            /*
             * Unique whatever the casing, matching the functional index on the
             * table. A catalogue holding "Samsung" and "samsung" has one
             * manufacturer's products split across two brands that filter
             * separately, and each storefront page shows half the range.
             *
             * Written by hand because `Rule::unique` compares the column exactly
             * before anything else is applied, so "Samsung" never reaches a
             * lowercased condition added beside it.
             */
            'name' => [
                'required', 'string', 'max:120',
                function (string $attribute, mixed $value, Closure $fail) use ($existing): void {
                    $existingId = $existing?->id;

                    $taken = Brand::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->when($existingId !== null, fn (Builder $query) => $query->whereKeyNot($existingId))
                        ->exists();

                    if ($taken) {
                        $fail(__('catalog.brands.name_taken'));
                    }
                },
            ],

            'slug' => [
                'nullable', 'string', 'max:140', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(Brand::class, 'slug')->ignore($existing?->id),
            ],

            'description' => ['nullable', 'string', 'max:5000'],

            /*
             * The brand mark (§11.3).
             *
             * `mimetypes` rather than `mimes`: the first reads the file's own
             * bytes, the second trusts the extension somebody typed. The size
             * and the accepted list are {@see CatalogImageStore}'s, stated here
             * so the person gets a message against the field rather than a
             * refusal after the form closes — the store checks them again,
             * because that is the check a future caller cannot skip.
             */
            'logo' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', CatalogImageStore::ACCEPTED_MIME_TYPES),
                'max:'.(int) (CatalogImageStore::MAX_BYTES / 1024),
            ],
            'remove_logo' => ['boolean'],
            'logo_alt' => ['nullable', 'string', 'max:255'],

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
            'logo_alt' => 'logo alt text',
        ];
    }
}
