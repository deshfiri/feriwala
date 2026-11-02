<?php

namespace App\Http\Requests\Mobile\Account;

use App\Domain\Account\VerificationCodes;
use Illuminate\Foundation\Http\FormRequest;

class VerifyMobileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The six-digit code sent by SMS. */
            'code' => ['required', 'digits:'.VerificationCodes::LENGTH],
        ];
    }
}
