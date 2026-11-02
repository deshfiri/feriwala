<?php

namespace App\Http\Requests\Mobile\Supplier\Stock;

use Illuminate\Foundation\Http\FormRequest;

class SubmitStockUpdateRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** The quantity on hand the Supplier wants recorded. Takes effect only once staff approve it. */
            'quantity' => ['required', 'integer', 'min:0'],
            /** A note for the reviewer. */
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
