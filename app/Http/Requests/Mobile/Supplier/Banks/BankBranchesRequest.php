<?php

namespace App\Http\Requests\Mobile\Supplier\Banks;

use Illuminate\Foundation\Http\FormRequest;

class BankBranchesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** `id` of the district, from `GET /supplier/locations/division/{source_id}/children`. */
            'district_id' => ['required', 'integer'],
        ];
    }
}