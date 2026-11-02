<?php

namespace App\Http\Requests\Mobile\PayoutMethods;

use Illuminate\Foundation\Http\FormRequest;

class ArchivePayoutMethodRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The signed-in person's own password. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
