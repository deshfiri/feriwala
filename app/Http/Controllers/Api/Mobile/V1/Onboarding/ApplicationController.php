<?php

namespace App\Http\Controllers\Api\Mobile\V1\Onboarding;

use App\Domain\Account\Actions\ChangeOwnerMobile;
use App\Domain\Account\Actions\SaveBusinessSetup;
use App\Domain\Account\Actions\SavePersonalDetails;
use App\Domain\Account\Actions\SubmitApplication;
use App\Domain\Account\Data\BusinessSetup;
use App\Domain\Account\Data\PersonalDetails;
use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Account\OnboardingProgress;
use App\Domain\Account\PreferredDomainExtensions;
use App\Domain\Account\Queries\PreferredDomainCheck;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use App\Http\Controllers\Api\Mobile\V1\MobileController;
use App\Http\Resources\Mobile\Onboarding\OnboardingProgressResource;
use App\Models\User;
use App\Support\Localization\Countries;
use App\Support\Localization\InternationalMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The partner's application in the app (D39): personal details, business setup,
 * the preferred-domain check, the review and the submission — the same steps,
 * rules and actions as the web, in the same order.
 *
 * Each step is open once the package is paid for, and until the application is
 * submitted (or sent back for correction); otherwise `422` with `errors.onboarding`.
 */
class ApplicationController extends MobileController
{
    public function __construct(
        protected OnboardingApplication $application,
        protected Countries $countries,
        protected InternationalMobile $mobiles,
        protected PreferredDomainExtensions $extensions,
    ) {}

    /**
     * Application summary
     *
     * Everything the partner has given so far, the options the forms need, and
     * whether each step is open.
     */
    public function show(Request $request): JsonResponse
    {
        [$owner, $account] = $this->context($request);

        $editable = true;

        try {
            $this->application->assertEditable($account);
        } catch (OnboardingRefused) {
            $editable = false;
        }

        return new JsonResponse([
            'editable' => $editable,
            'application' => $this->application->summary($account),
            'mobile' => $this->mobiles->split($owner->mobile, (string) ($owner->country ?? 'BD')),
            'options' => [
                'countries' => $this->countries->options(),
                'dialling_codes' => $this->mobiles->options(),
                'domain_extensions' => $this->extensions->enabled(),
            ],
        ]);
    }

    /**
     * Save personal details
     *
     * Legal name, mobile (country + number; a changed number is unverified until
     * its new code is entered — `mobile_changed: true`), country of residence,
     * nationality, date of birth (18 or older) and residential address: the BD
     * location ids for a Bangladeshi residence, address lines otherwise.
     */
    public function updateDetails(Request $request, SavePersonalDetails $save, ChangeOwnerMobile $changeMobile): JsonResponse
    {
        [$owner, $account] = $this->context($request);

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'min:2', 'max:255'],
            'mobile_country' => ['required', 'string', 'size:2', Rule::in(array_keys($this->mobiles->codes()))],
            'mobile_number' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2', Rule::in($this->countries->codes())],
            'nationality' => ['required', 'string', 'size:2', Rule::in(array_keys($this->mobiles->codes()))],
            'date_of_birth' => ['required', 'date', 'before:-18 years', 'after:1900-01-01'],
            'division_id' => ['exclude_unless:country,BD', 'required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Division)],
            'district_id' => ['exclude_unless:country,BD', 'required', 'integer', new ValidBdLocationHierarchy(BdLocationType::District)],
            'upazila_id' => ['exclude_unless:country,BD', 'required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            'union_id' => ['exclude_unless:country,BD', 'nullable', 'integer', new ValidBdLocationHierarchy(BdLocationType::Union)],
            'detailed_address' => ['exclude_unless:country,BD', 'required', 'string', 'max:500'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'line_1' => ['exclude_if:country,BD', 'required', 'string', 'max:255'],
            'line_2' => ['exclude_if:country,BD', 'nullable', 'string', 'max:255'],
            'city' => ['exclude_if:country,BD', 'required', 'string', 'max:120'],
        ]);

        $mobile = $this->mobiles->normalise($validated['mobile_country'], $validated['mobile_number'])
            ?? throw ValidationException::withMessages(['mobile_number' => __('onboarding.refused.mobile_invalid')]);

        $bd = strtoupper($validated['country']) === 'BD';

        try {
            $save->handle($account, new PersonalDetails(
                legalName: $validated['legal_name'],
                country: $validated['country'],
                nationality: $validated['nationality'],
                dateOfBirth: $validated['date_of_birth'],
                bdAddress: $bd ? [
                    'division_id' => (int) $validated['division_id'],
                    'district_id' => (int) $validated['district_id'],
                    'upazila_id' => (int) $validated['upazila_id'],
                    'union_id' => isset($validated['union_id']) ? (int) $validated['union_id'] : null,
                    'detailed_address' => $validated['detailed_address'],
                    'postcode' => $validated['postcode'] ?? null,
                ] : null,
                foreignAddress: $bd ? null : [
                    'line_1' => $validated['line_1'],
                    'line_2' => $validated['line_2'] ?? null,
                    'city' => $validated['city'],
                    'postcode' => $validated['postcode'] ?? null,
                ],
            ));

            $mobileChanged = $changeMobile->handle($account, $owner, $mobile);
        } catch (OnboardingRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return new JsonResponse([
            'mobile_changed' => $mobileChanged,
            'application' => $this->application->summary($account->refresh()),
        ]);
    }

    /**
     * Save business setup
     *
     * Business or shop name, preferred domain (a name and one of
     * `options.domain_extensions` — saved for staff to check and buy by hand;
     * never bought automatically), and optionally a business mobile, email and
     * BD address. `use_personal_mobile` / `use_personal_email` true use the
     * owner's own.
     */
    public function updateBusiness(Request $request, SaveBusinessSetup $save): JsonResponse
    {
        [, $account] = $this->context($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'domain_name' => ['required', 'string', 'max:63'],
            'domain_extension' => ['required', 'string', 'max:24'],
            'use_personal_mobile' => ['required', 'boolean'],
            'mobile_country' => ['exclude_if:use_personal_mobile,true', 'required', 'string', 'size:2', Rule::in(array_keys($this->mobiles->codes()))],
            'mobile_number' => ['exclude_if:use_personal_mobile,true', 'required', 'string', 'max:20'],
            'use_personal_email' => ['required', 'boolean'],
            'email' => ['exclude_if:use_personal_email,true', 'required', 'email:rfc', 'max:191'],
            'has_address' => ['required', 'boolean'],
            'division_id' => ['exclude_unless:has_address,true', 'required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Division)],
            'district_id' => ['exclude_unless:has_address,true', 'required', 'integer', new ValidBdLocationHierarchy(BdLocationType::District)],
            'upazila_id' => ['exclude_unless:has_address,true', 'required', 'integer', new ValidBdLocationHierarchy(BdLocationType::Upazila)],
            'union_id' => ['exclude_unless:has_address,true', 'nullable', 'integer', new ValidBdLocationHierarchy(BdLocationType::Union)],
            'detailed_address' => ['exclude_unless:has_address,true', 'required', 'string', 'max:500'],
            'postcode' => ['exclude_unless:has_address,true', 'nullable', 'string', 'max:20'],
        ]);

        $mobile = null;

        if (! $validated['use_personal_mobile']) {
            $mobile = $this->mobiles->normalise($validated['mobile_country'], $validated['mobile_number'])
                ?? throw ValidationException::withMessages(['mobile_number' => __('onboarding.refused.mobile_invalid')]);
        }

        try {
            $save->handle($account, new BusinessSetup(
                businessName: $validated['name'],
                domainName: $validated['domain_name'],
                domainExtension: $validated['domain_extension'],
                businessMobile: $mobile,
                businessEmail: $validated['use_personal_email'] ? null : $validated['email'],
                address: $validated['has_address'] ? [
                    'division_id' => (int) $validated['division_id'],
                    'district_id' => (int) $validated['district_id'],
                    'upazila_id' => (int) $validated['upazila_id'],
                    'union_id' => isset($validated['union_id']) ? (int) $validated['union_id'] : null,
                    'detailed_address' => $validated['detailed_address'],
                    'postcode' => $validated['postcode'] ?? null,
                ] : null,
            ));
        } catch (OnboardingRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return new JsonResponse(['application' => $this->application->summary($account->refresh())]);
    }

    /**
     * Check a preferred domain
     *
     * What Banij can say about it: `invalid`, `taken_on_banij` (another partner
     * already asked for or uses it), or `unverified` — not checked with any
     * registrar, availability not guaranteed. Never "available". Alternatives
     * are suggested either way; `disclaimer` must be shown with the result.
     */
    public function checkDomain(Request $request, PreferredDomainCheck $check): JsonResponse
    {
        [, $account] = $this->context($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:63'],
            'extension' => ['required', 'string', 'max:24'],
        ]);

        return new JsonResponse([
            ...$check->check($account, $validated['name'], $validated['extension']),
            'disclaimer' => __('onboarding.business.domain_check_disclaimer'),
        ]);
    }

    /**
     * Submit the application
     *
     * Needs the package paid, identity documents submitted, and personal details
     * and business setup complete; `confirm` must be true. The account then
     * waits for Banij's verification — it is never activated here. Submitting
     * again changes nothing.
     */
    public function submit(Request $request, SubmitApplication $submit, OnboardingProgress $progress): OnboardingProgressResource
    {
        [$owner, $account] = $this->context($request);

        $request->validate(['confirm' => ['accepted']]);

        try {
            $submit->handle($account, $owner);
        } catch (OnboardingRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return new OnboardingProgressResource($progress->for($account->refresh()));
    }

    /**
     * @return array{0: User, 1: BusinessAccount}
     */
    protected function context(Request $request): array
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 403);

        $account = $user->businessAccount;
        abort_if($account === null || $account->owner_id !== $user->id, 403, 'Only the account owner fills in the application.');

        return [$user, $account];
    }
}
