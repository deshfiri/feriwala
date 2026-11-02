<?php

namespace App\Http\Requests\Mobile\Account;

use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The person's full name. */
            'name' => $this->nameRules(),
            /** Login email address. Changing it clears the email verification. */
            'email' => $this->emailRules($this->user()?->getKey()),
        ];
    }
}
