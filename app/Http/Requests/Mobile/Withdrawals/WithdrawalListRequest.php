<?php

namespace App\Http\Requests\Mobile\Withdrawals;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Only withdrawals in this status, one of `statuses[].value`. Unknown values match nothing. */
            'status' => ['nullable', 'string', 'max:30'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
