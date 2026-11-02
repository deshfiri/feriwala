<?php

namespace App\Http\Requests\Mobile\Supplier\Listings;

use App\Domain\Supplier\Enums\ListingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListingListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Only listings in this status. */
            'status' => ['nullable', 'string', Rule::enum(ListingStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
