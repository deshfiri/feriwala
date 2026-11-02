<?php

namespace App\Http\Requests\Mobile\Websites;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMaintenanceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** `true` to put the storefront into maintenance, `false` to take it back out. */
            'enabled' => ['required', 'boolean'],
            /** What visitors are told while it is down. Ignored when switching maintenance off. */
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
