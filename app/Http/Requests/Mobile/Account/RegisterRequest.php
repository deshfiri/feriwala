<?php

namespace App\Http\Requests\Mobile\Account;

use App\Actions\Fortify\CreateNewUser;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Domain\Account\Enums\Gender;
use App\Models\User;
use App\Support\Localization\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Registering a Feriwala account from the app.
 *
 * Mirrors the rules {@see CreateNewUser} applies, which stays
 * the authority: it validates again before anything is written.
 */
class RegisterRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The person's full name. */
            'name' => $this->nameRules(),
            /** Login email address. Must not already be registered. */
            'email' => $this->emailRules(),
            /**
             * Mobile number, unique across accounts.
             *
             * @example +8801712345678
             */
            'mobile' => ['required', 'string', 'max:20', Rule::unique(User::class, 'mobile')],
            /** Password; the policy is `password_rules` on `GET /register/options`. */
            'password' => $this->passwordRules(),
            /** Must equal `password`. */
            'password_confirmation' => ['required', 'string'],
            /** `YYYY-MM-DD`, after 1900-01-01 and before today. */
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            /** One of the `genders` values from `GET /register/options`. */
            'gender' => ['nullable', Rule::in(Gender::values())],
            /** A country code from `countries` on `GET /register/options`; defaults to `default_country`. */
            'country' => ['nullable', Rule::in(app(Countries::class)->codes())],
            'nationality' => ['nullable', 'string', 'max:64'],
            /** A referrer's code, usually carried in from a referral link's `ref` parameter. */
            'referral_code' => ['nullable', 'string', 'max:16'],
            /** The token of a staff invitation link, to join that business instead of opening a new one. */
            'invitation' => ['nullable', 'string', 'max:64'],
            /** Must be `true`. */
            'terms_accepted' => ['accepted'],
            /** Must be `true`. */
            'privacy_accepted' => ['accepted'],
            /** A label for this phone, shown in the signed-in devices list. */
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}
