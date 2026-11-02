<?php

namespace App\Http\Requests\Mobile\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The `challenge_token` returned by the login endpoint. */
            'challenge_token' => ['required', 'string'],
            /** The six-digit code from the authenticator app. Send this or `recovery_code`. */
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            /** One of the person's unused recovery codes. Send this or `code`. */
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
