<?php

namespace App\Http\Requests\Mobile\Supplier\Registration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Registering a Supplier from the app.
 *
 * Mirrors the shape {@see \App\Domain\Supplier\Actions\RegisterSupplier} checks,
 * which stays the authority: it normalises the mobile number and validates
 * uniqueness again before anything is written.
 */
class RegisterSupplierRequest extends FormRequest
{
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
            /** The trading name of the supplying business. */
            'business_name' => ['required', 'string', 'max:255'],
            /** The person Feriwala deals with. */
            'contact_person_name' => ['required', 'string', 'max:255'],
            /** Registered business address, free text. */
            'business_address' => ['required', 'string', 'max:1000'],
            /** Login email address. Must not already be registered to a supplier. */
            'email' => ['required', 'string', 'email', 'max:255'],
            /**
             * Mobile number; local formats are normalised to international form.
             *
             * @example +8801712345678
             */
            'mobile' => ['required', 'string', 'max:20'],
            /** At least the platform password policy. */
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            /** Must equal `password`. */
            'password_confirmation' => ['required', 'string'],
            /** Trade licence number, when the business has one. */
            'trade_licence_number' => ['nullable', 'string', 'max:255'],
            /** Tax identification number, when the business has one. */
            'tax_identification_number' => ['nullable', 'string', 'max:255'],
            /** A label for this phone, shown against its token. */
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }
}