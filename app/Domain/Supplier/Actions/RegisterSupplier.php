<?php

namespace App\Domain\Supplier\Actions;

use App\Actions\Fortify\CreateNewUser;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Enums\SupplierStatusChangeSource;
use App\Domain\Supplier\Models\Supplier;
use App\Support\Localization\MobileNumber;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Opens a Supplier account (D25, P13-1).
 *
 * A wholly separate registration from {@see CreateNewUser}:
 * no business account is opened, no membership is created, and nothing here
 * ever touches `App\Models\User` or `BusinessAccount`. Registration only opens
 * the identity — it starts at {@see SupplierStatus::Draft} and moves to
 * {@see SupplierStatus::VerificationPending} in the same step, then must still
 * verify its email and mobile, submit KYC, and be approved before it may
 * submit a listing request.
 */
class RegisterSupplier
{
    public function __construct(
        protected DatabaseManager $database,
        protected MobileNumber $mobiles,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function validator(array $input): Validator
    {
        return validator($input, [
            'business_name' => ['required', 'string', 'max:255'],
            'contact_person_name' => ['required', 'string', 'max:255'],
            'business_address' => ['required', 'string', 'max:1000'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(Supplier::class, 'email')],
            // Normalised to E.164 before this runs, so the regex is a safety
            // net against a number the normaliser could not resolve — it
            // mirrors the `suppliers_mobile_is_e164` check constraint exactly,
            // so a rejection here is a validation error rather than a 500.
            'mobile' => ['required', 'string', 'max:20', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique(Supplier::class, 'mobile')],
            'password' => ['required', 'string', 'confirmed', Password::default()],
            'trade_licence_number' => ['nullable', 'string', 'max:255'],
            'tax_identification_number' => ['nullable', 'string', 'max:255'],
        ], [
            'mobile.regex' => 'That mobile number could not be resolved.',
        ]);
    }

    /**
     * @param  array<string, string>  $input
     */
    public function handle(array $input): Supplier
    {
        if (is_string($input['mobile'] ?? null)) {
            $input['mobile'] = $this->mobiles->normalise($input['mobile']) ?? $input['mobile'];
        }

        $this->validator($input)->validate();

        return $this->database->transaction(function () use ($input) {
            $supplier = Supplier::create([
                'business_name' => $input['business_name'],
                'contact_person_name' => $input['contact_person_name'],
                'business_address' => $input['business_address'],
                'email' => $input['email'],
                'mobile' => $input['mobile'],
                'password' => $input['password'],
                'trade_licence_number' => $input['trade_licence_number'] ?? null,
                'tax_identification_number' => $input['tax_identification_number'] ?? null,
            ]);

            // The first history row has no previous state to transition from,
            // so it is recorded rather than moved through transitionTo().
            $supplier->recordStatusChange(
                null,
                SupplierStatus::Draft,
                StatusChange::bySystem(reason: 'Registration submitted.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );

            $supplier->transitionWithHistory(
                SupplierStatus::VerificationPending,
                new StatusChange(reason: 'Registration submitted; awaiting email and mobile verification.'),
                ['source' => SupplierStatusChangeSource::Supplier],
            );

            return $supplier;
        });
    }
}
