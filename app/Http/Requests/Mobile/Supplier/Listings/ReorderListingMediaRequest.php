<?php

namespace App\Http\Requests\Mobile\Supplier\Listings;

use Illuminate\Foundation\Http\FormRequest;

class ReorderListingMediaRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** The listing's own images, by `id`, in the order wanted. Images left out follow in their current order. */
            'order' => ['required', 'array'],
            'order.*' => ['string'],
        ];
    }
}
