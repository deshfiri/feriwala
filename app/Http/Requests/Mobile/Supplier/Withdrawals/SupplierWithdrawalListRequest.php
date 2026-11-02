<?php

namespace App\Http\Requests\Mobile\Supplier\Withdrawals;

use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierWithdrawalListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Only withdrawals in this status, from `statuses` on this endpoint. */
            'status' => ['nullable', 'string', Rule::enum(SupplierWithdrawalStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
