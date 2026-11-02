<?php

namespace App\Http\Requests\Mobile\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A sensitive action confirmed with the password.
 *
 * The web asks for the password through a session-based confirmation step. A
 * token client has no session, so the password rides in the request itself.
 */
class CurrentPasswordRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The person's current password. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }
}
