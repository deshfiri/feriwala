<?php

namespace App\Http\Requests\Mobile\Supplier\Listings;

use App\Domain\Supplier\Models\SupplierListingMedia;
use App\Domain\Supplier\SupplierListingMediaStore;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * One image for a listing, sent as `multipart/form-data`.
 */
class StoreListingMediaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** The image file (JPEG, PNG or WebP) within the size limit. */
            'file' => [
                'required',
                'file',
                'mimetypes:'.implode(',', SupplierListingMediaStore::IMAGE_TYPES),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile && $value->getSize() > SupplierListingMediaStore::maxBytes()) {
                        $fail(sprintf('This image is too large. The limit is %d MB.', (int) (SupplierListingMediaStore::maxBytes() / (1024 * 1024))));
                    }
                },
            ],
            /** `primary` (one per listing) or `gallery`. */
            'role' => ['required', Rule::in([SupplierListingMedia::ROLE_PRIMARY, SupplierListingMedia::ROLE_GALLERY])],
            /** Describes the image for accessibility. Required. */
            'alt_text' => ['required', 'string', 'max:255'],
            /** `id` of one of this listing's variations, for an image that belongs to that variation only. */
            'variant_id' => ['nullable', 'string'],
        ];
    }
}
