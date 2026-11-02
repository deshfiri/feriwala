<?php

namespace App\Http\Requests\Mobile\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class WalletStatementRequest extends FormRequest
{
    /**
     * Unknown values are ignored rather than refused, exactly as the web
     * statement treats them ({@see \App\Domain\Wallet\Queries\WalletStatement::acceptedFilters()}).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Movement type, from `options.types` on this endpoint. */
            'type' => ['nullable', 'string'],
            /** Movement status, from `options.statuses`. */
            'status' => ['nullable', 'string'],
            /** `debit` or `credit`. */
            'direction' => ['nullable', 'string'],
            /** First day to include, `YYYY-MM-DD`. */
            'from' => ['nullable', 'string'],
            /** Last day to include, `YYYY-MM-DD`. */
            'to' => ['nullable', 'string'],
            /** Matches the reference or description. */
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
