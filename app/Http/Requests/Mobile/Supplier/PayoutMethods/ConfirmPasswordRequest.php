<?php

namespace App\Http\Requests\Mobile\Supplier\PayoutMethods;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The supplier's current password; changing payout details is sensitive. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}