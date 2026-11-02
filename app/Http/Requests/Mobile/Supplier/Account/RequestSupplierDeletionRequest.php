<?php

namespace App\Http\Requests\Mobile\Supplier\Account;

use App\Domain\Account\Actions\RequestAccountDeletion;
use Illuminate\Foundation\Http\FormRequest;

class RequestSupplierDeletionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** The supplier's current password. */
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            /** Why the account should be deleted; five to a thousand characters. */
            'reason' => ['required', 'string', 'min:'.RequestAccountDeletion::MINIMUM_REASON, 'max:1000'],
        ];
    }
}