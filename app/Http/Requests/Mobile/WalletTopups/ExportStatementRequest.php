<?php

namespace App\Http\Requests\Mobile\WalletTopups;

use Illuminate\Foundation\Http\FormRequest;

class ExportStatementRequest extends FormRequest
{
    /**
     * The same filters as the statement list; unknown values are ignored exactly as the web export ignores them.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Movement type, from `options.types` of the statement list. */
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
        ];
    }
}
