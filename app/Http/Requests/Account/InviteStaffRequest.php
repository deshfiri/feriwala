<?php

namespace App\Http\Requests\Account;

use App\Domain\Account\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteStaffRequest extends FormRequest
{
    /**
     * Authorisation is the controller's, through the policy. Doing it here as
     * well would put the account lookup in two places.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],

            // Owner is absent from `invitable()`: an account has one owner,
            // established at registration. Validation refuses it here so the
            // action's own refusal is a backstop rather than the only guard.
            'role' => ['required', Rule::enum(AccountRole::class)->only(AccountRole::invitable())],

            // Optional second factor on who may accept. When given, the invitee
            // must hold that verified mobile as well as the email.
            'mobile' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.Illuminate\Validation\Rules\Enum' => __('Choose Manager or Staff.'),
        ];
    }
}
