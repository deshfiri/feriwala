<?php

namespace App\Http\Requests\Account;

use App\Domain\Account\Data\LocaleChange;
use App\Support\Localization\Locale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a language change and turns it into a typed Data object.
 *
 * Validation lives here rather than in the controller or the Action, so the
 * shape of the input is settled in exactly one place and the Action can trust
 * what it receives.
 */
class ChangeLocaleRequest extends FormRequest
{
    /**
     * Anyone may change the language they are reading in, signed in or not —
     * the public site and the login screen are both translated.
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
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
        ];
    }

    /**
     * Hand the Action a typed intent rather than a bag of request input.
     */
    public function toData(): LocaleChange
    {
        return new LocaleChange(
            locale: Locale::from($this->string('locale')->toString()),
            user: $this->user(),
        );
    }
}
