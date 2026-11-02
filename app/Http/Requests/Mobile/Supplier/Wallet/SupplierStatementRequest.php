<?php

namespace App\Http\Requests\Mobile\Supplier\Wallet;

use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupplierStatementRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** Entry type, from `types` on this endpoint. */
            'type' => ['nullable', 'string', Rule::enum(SupplierLedgerEntryType::class)],
            /** First day to include, `YYYY-MM-DD`. */
            'date_from' => ['nullable', 'date'],
            /** Last day to include, `YYYY-MM-DD`. */
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
