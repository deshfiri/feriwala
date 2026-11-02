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
            /** `id` of a district from the locations lookup. */
            'district_id' => ['required', 'integer'],
        ];
    }
}
