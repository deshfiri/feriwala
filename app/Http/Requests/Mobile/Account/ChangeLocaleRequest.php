<?php

namespace App\Http\Requests\Mobile\Account;

use App\Support\Localization\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeLocaleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The interface language: `en` or `bn`. */
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ];
    }
}
