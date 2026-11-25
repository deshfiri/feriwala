<?php

namespace App\Http\Controllers\Erp;

use App\Domain\Account\Actions\ChangeOwnerMobile;
use App\Domain\Account\Actions\SaveBusinessSetup;
use App\Domain\Account\Actions\SavePersonalDetails;
use App\Domain\Account\Actions\SubmitApplication;
use App\Domain\Account\Data\BusinessSetup;
use App\Domain\Account\Data\PersonalDetails;
use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Account\PreferredDomainExtensions;
use App\Domain\Account\Queries\PreferredDomainCheck;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Location\Rules\ValidBdLocationHierarchy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Localization\Countries;
use App\Support\Localization\InternationalMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The partner's application, onboarding steps 5 to 7 (D39): personal details,
 * business setup, and the review they submit for verification.
 *
 * Each step is saved as it is completed, so the partner can leave and come
 * back, and go back to an earlier step until they submit. Validates and hands
 * over; the rules — paid first, editable until submitted — live in the actions.
 */
class OnboardingApplicationController extends Controller
{
    public function __construct(
        protected OnboardingApplication $application,
        protected Countries $countries,
        protected InternationalMobile $mobiles,
        protected PreferredDomainExtensions $extensions,
    ) {}

    public function details(Request $request): Response|RedirectResponse
    {
        [$owner, $account] = $this->context($request);

        if (($redirect = $this->unlessEditable($account)) !== null) {
            return $redirect;
        }

        $residential = $this->application->residentialAddress($account);
        $foreign = $this->application->foreignAddress($account);
        $mobile = $this->mobiles->split($owner->mobile, (string) ($owner->country ?? 'BD'));

        return Inertia::render('onboarding/details', [
            'details' => [
                'legal_name' => $owner->name,
                'email' => $owner->email,
                'mobile_country' => $mobile['country'] ?? ($owner->country ?? 'BD'),
                'mobile_number' => $mobile['national'] ?? $owner->mobile,
                'mobile_verified' => $owner->mobile_verified_at !== null,
                'country' => $owner->country ?? $this->countries->default(),
                'nationality' => $owner->nationality,
                'date_of_birth' => $owner->date_of_birth?->toDateString(),
                'bd_address' => $residential === null ? null : $this->bdAddress($residential),
                'foreign_address' => $foreign === null ? null : [
                    'line_1' => $foreign->line_1,
                    'line_2' => $foreign->line_2,
                    'city' => $foreign->city,
                    'postcode' => $foreign->postcode,
                ],
            ],
            'countries' => $this->countries->options(),
            'nationalities' => $this->nationalities(),
            'dialling_codes' => $this->mobiles->options(),
            'correction' => $this->correction($account, 'personal'),
        ]);
    }

    public function updateDetails(Request $request, SavePersonalDetails $save, ChangeOwnerMobile $changeMobile): RedirectResponse
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

            // A new number is verified before anything else (D39): the code is
            // on its way, and the verification screen takes it.
            if ($changeMobile->handle($account, $owner, $mobile)) {
                Inertia::flash('toast', ['type' => 'success', 'message' => __('onboarding.details.mobile_changed')]);

                return to_route('verification.mobile');
            }
        } catch (OnboardingRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return to_route('onboarding.business.edit');
    }

    public function business(Request $request): Response|RedirectResponse
    {
        [$owner, $account] = $this->context($request);

        if (($redirect = $this->unlessEditable($account)) !== null) {
            return $redirect;
        }

        if ($account->personal_details_completed_at === null) {
            return to_route('onboarding.details.edit');
        }

        $address = $this->application->businessAddress($account);
        $businessMobile = $this->mobiles->split($account->business_mobile, (string) ($owner->country ?? 'BD'));

        return Inertia::render('onboarding/business', [
            'business' => [
                // The account was named after its owner at registration; a name
                // the partner has not chosen yet is offered blank, not as theirs.
                'name' => $account->business_setup_completed_at === null ? '' : $account->name,
                'domain_name' => $account->preferred_domain_name ?? '',
                'domain_extension' => $account->preferred_domain_extension ?? ($this->extensions->enabled()[0] ?? '.com'),
                'use_personal_mobile' => $account->business_mobile === null,
                'mobile_country' => $businessMobile['country'] ?? ($owner->country ?? 'BD'),
                'mobile_number' => $businessMobile['national'] ?? '',
                'use_personal_email' => $account->business_email === null,
                'email' => $account->business_email ?? '',
                'has_address' => $address !== null,
                'address' => $address === null ? null : $this->bdAddress($address),
            ],
            'personal' => ['mobile' => $owner->mobile, 'email' => $owner->email],
            'extensions' => $this->extensions->enabled(),
            'dialling_codes' => $this->mobiles->options(),
            'correction' => $this->correction($account, 'business', 'domain'),
        ]);
    }

    public function updateBusiness(Request $request, SaveBusinessSetup $save): RedirectResponse
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

        return to_route('onboarding.review.show');
    }

    /**
     * What Banij can say about a preferred domain (D39): valid or not, offered
     * or not, already asked for on Banij or not — and otherwise "not verified".
     * Never "available": there is no registrar check, and staff verify by hand.
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

    public function review(Request $request): Response|RedirectResponse
    {
        [, $account] = $this->context($request);

        if (($redirect = $this->unlessEditable($account)) !== null) {
            return $redirect;
        }

        return Inertia::render('onboarding/review', [
            'application' => $this->application->summary($account),
        ]);
    }

    public function submit(Request $request, SubmitApplication $submit): RedirectResponse
    {
        [$owner, $account] = $this->context($request);

        $request->validate(['confirm' => ['accepted']]);

        try {
            $submit->handle($account, $owner);
        } catch (OnboardingRefused $refused) {
            throw ValidationException::withMessages([$refused->field => $refused->getMessage()]);
        }

        return to_route('onboarding.status');
    }

    /**
     * @return array{0: User, 1: BusinessAccount}
     */
    protected function context(Request $request): array
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 403);

        $account = $user->businessAccount;
        // Only the owner fills in the application (D23): it is about them.
        abort_if($account === null || $account->owner_id !== $user->id, 403);

        return [$user, $account];
    }

    /** Back to the status page when this step is not open — not paid yet, or already submitted. */
    protected function unlessEditable(BusinessAccount $account): ?RedirectResponse
    {
        try {
            $this->application->assertEditable($account);
        } catch (OnboardingRefused $refused) {
            Inertia::flash('toast', ['type' => 'info', 'message' => $refused->getMessage()]);

            return to_route('onboarding.status');
        }

        return null;
    }

    /**
     * What a reviewer asked to be corrected in these sections, if anything.
     *
     * @return array{feedback: string|null}|null
     */
    protected function correction(BusinessAccount $account, string ...$sections): ?array
    {
        $asked = array_intersect($sections, $account->correction_sections ?? []);

        return $asked === [] ? null : ['feedback' => $account->correction_feedback];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function nationalities(): array
    {
        return array_values(array_map(
            fn (array $option) => ['value' => $option['value'], 'label' => preg_replace('/ \(\+\d+\)$/', '', $option['label']) ?? $option['label']],
            $this->mobiles->options(),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    protected function bdAddress(SharedAddress $address): array
    {
        $option = fn (?BdLocation $location) => $location === null ? null : [
            'id' => $location->id,
            'source_id' => $location->source_id,
            'name_en' => $location->name_en,
            'name_bn' => $location->name_bn,
        ];

        return [
            'detailed_address' => $address->detailed_address,
            'postcode' => $address->postcode,
            'location' => [
                'division' => $option($address->division),
                'district' => $option($address->district),
                'upazila' => $option($address->upazila),
                'union' => $option($address->union),
            ],
        ];
    }
}
