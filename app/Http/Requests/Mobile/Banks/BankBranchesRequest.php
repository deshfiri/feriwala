<?php

namespace App\Http\Requests\Mobile\Banks;

use Illuminate\Foundation\Http\FormRequest;

class BankBranchesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** `id` of a district from the locations lookup. Leave it out to get every branch of the bank. */
            'district_id' => ['nullable', 'integer'],
        ];
    }
}
