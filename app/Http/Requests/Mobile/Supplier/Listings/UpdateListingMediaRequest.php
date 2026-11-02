<?php

namespace App\Http\Requests\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierListingMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateListingMediaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** `primary` or `gallery`. */
            'role' => ['sometimes', Rule::in([SupplierListingMedia::ROLE_PRIMARY, SupplierListingMedia::ROLE_GALLERY])],
            /** Describes the image for accessibility. */
            'alt_text' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
