<?php

namespace App\Http\Requests\Account;

use App\Domain\Account\Enums\AccountRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRoleRequest extends FormRequest
{
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
            // Owner is not in the list. Ownership is not granted by changing
            // somebody's permissions, and a form that offers it would make it
            // look as though it were.
            'role' => ['required', Rule::enum(AccountRole::class)->only(AccountRole::invitable())],
        ];
    }
}
