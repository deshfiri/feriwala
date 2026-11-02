<?php

namespace App\Http\Requests\Mobile\Account;

use App\Domain\Account\Actions\RequestAccountDeletion;
use Illuminate\Foundation\Http\FormRequest;

class DeletionRequestRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The person's current password. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            /** Why the account should be deleted; five to a thousand characters. */
            'reason' => ['required', 'string', 'min:'.RequestAccountDeletion::MINIMUM_REASON, 'max:1000'],
        ];
    }
}
