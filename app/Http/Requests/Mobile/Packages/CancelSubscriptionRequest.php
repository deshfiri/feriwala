<?php

namespace App\Http\Requests\Mobile\Packages;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancelling the current term (§8.2).
 */
class CancelSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** Why the term is being cancelled, in the account holder's words. */
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
