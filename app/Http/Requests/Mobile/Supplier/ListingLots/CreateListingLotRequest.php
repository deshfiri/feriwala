<?php

namespace App\Http\Requests\Mobile\Supplier\ListingLots;

use Illuminate\Foundation\Http\FormRequest;

class CreateListingLotRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** A name for the batch. Optional. */
            'title' => ['nullable', 'string', 'max:255'],
        ];
    }
}
