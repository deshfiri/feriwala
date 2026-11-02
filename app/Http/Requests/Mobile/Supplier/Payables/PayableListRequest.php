<?php

namespace App\Http\Requests\Mobile\Supplier\Payables;

use App\Domain\Supplier\Enums\PayableStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PayableListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Only payables in this status, from `statuses` on this endpoint. */
            'status' => ['nullable', 'string', Rule::enum(PayableStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
