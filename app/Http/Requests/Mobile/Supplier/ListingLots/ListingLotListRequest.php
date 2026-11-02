<?php

namespace App\Http\Requests\Mobile\Supplier\ListingLots;

use App\Domain\Supplier\Enums\LotStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListingLotListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Only batches in this status. */
            'status' => ['nullable', 'string', Rule::enum(LotStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
