<?php

namespace App\Domain\Account;

use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Kyc\Models\KycSubmission;
use App\Models\User;
use App\Support\Localization\Countries;
use App\Support\Localization\InternationalMobile;

/**
 * The partner's application (D39): personal details, business setup and the
 * submission that sends it to Banij for verification.
 *
 * The one place the onboarding screens, the review summary and the admin
 * review read it from, so the partner and the reviewer see the same thing.
 */
class OnboardingApplication
{
    /** The parts a reviewer may send back for correction. */
    public const SECTIONS = ['personal', 'business', 'domain'];

    public function __construct(
        protected ActivationRequirements $requirements,
        protected Countries $countries,
    ) {}

    /**
     * Personal details and business setup can be filled in once the package is
     * paid for (D39), and changed until the application is submitted — or again
     * when a reviewer asks for a correction, which takes the submission back.
     *
     * @throws OnboardingRefused
     */
    public function assertEditable(BusinessAccount $account): void
    {
        if (! $account->status->isOnboarding()) {
            throw OnboardingRefused::because('not_onboarding');
        }

        if (! $this->requirements->activationPaid($account)) {
            throw OnboardingRefused::because('pay_first');
        }

        if ($account->hasSubmittedApplication()) {
            throw OnboardingRefused::because('already_submitted');
        }
    }

    /** Whether the identity documents are in — submitted, under review or approved. */
    public function kycSubmitted(BusinessAccount $account): bool
    {
        return $this->requirements->kycApproved($account) || in_array($account->status, [
            AccountStatus::KycSubmitted,
            AccountStatus::KycUnderReview,
            AccountStatus::KycApproved,
        ], true);
    }

    public function residentialAddress(BusinessAccount $account): ?SharedAddress
    {
        return $this->sharedAddress($account, ClientAddressType::Residential);
    }

    public function businessAddress(BusinessAccount $account): ?SharedAddress
    {
        return $this->sharedAddress($account, ClientAddressType::Business);
    }

    public function foreignAddress(BusinessAccount $account): ?UserAddress
    {
        /** @var UserAddress|null $address */
        $address = UserAddress::query()->currentFor($account->owner_id, AddressType::Present)->first();

        return $address;
    }

    /**
     * Everything the partner gave, as the review screen and the reviewer read it.
     *
     * @return array<string, mixed>
     */
    public function summary(BusinessAccount $account): array
    {
        $owner = $account->owner;
        $residential = $this->residentialAddress($account);
        $foreign = $residential === null ? $this->foreignAddress($account) : null;
        $business = $this->businessAddress($account);

        return [
            'personal' => [
                'legal_name' => $owner?->name,
                'email' => $owner?->email,
                'mobile' => $owner?->mobile,
                'mobile_verified' => $owner?->mobile_verified_at !== null,
                'country' => $this->countries->nameFor($owner?->country) ?? $owner?->country,
                'nationality' => $this->countries->nameFor($owner?->nationality) ?? $owner?->nationality,
                'date_of_birth' => $owner?->date_of_birth?->toDateString(),
                'address' => $residential !== null ? $this->lines($residential) : ($foreign === null ? [] : array_values(array_filter([
                    $foreign->line_1, $foreign->line_2, $foreign->city, $foreign->postcode, $this->countries->nameFor($foreign->country) ?? $foreign->country,
                ]))),
                'complete' => $account->personal_details_completed_at !== null,
            ],
            'business' => [
                'name' => $account->name,
                'mobile' => $account->business_mobile ?? $owner?->mobile,
                'mobile_is_personal' => $account->business_mobile === null,
                'email' => $account->business_email ?? $owner?->email,
                'email_is_personal' => $account->business_email === null,
                'address' => $business === null ? [] : $this->lines($business),
                'complete' => $account->business_setup_completed_at !== null,
            ],
            'domain' => [
                'name' => $account->preferred_domain_name,
                'extension' => $account->preferred_domain_extension,
                'address' => $account->preferredDomain(),
                // Staff's manual check (D39); null until someone looks.
                'check' => $account->preferred_domain_check_result === null ? null : [
                    'result' => $account->preferred_domain_check_result,
                    'at' => $account->preferred_domain_checked_at?->toIso8601String(),
                    'by' => User::query()->whereKey($account->preferred_domain_checked_by)->value('name'),
                    'note' => $account->preferred_domain_check_note,
                ],
            ],
            'kyc_submitted' => $this->kycSubmitted($account),
            'kyc_issuing_country' => $this->issuingCountry($account),
            'kyc_approved' => $this->requirements->kycApproved($account),
            'paid' => $this->requirements->activationPaid($account),
            'submitted_at' => $account->application_submitted_at?->toIso8601String(),
            'correction' => ($account->correction_sections ?? []) === [] ? null : [
                'sections' => $account->correction_sections,
                'feedback' => $account->correction_feedback,
            ],
        ];
    }

    /** The country that issued the identity document on the latest round (D39), by name. */
    protected function issuingCountry(BusinessAccount $account): ?string
    {
        $code = KycSubmission::query()
            ->where('business_account_id', $account->id)
            ->orderByDesc('round')
            ->value('issuing_country');

        if (! is_string($code)) {
            return null;
        }

        return app(InternationalMobile::class)->codes()[$code][1] ?? $code;
    }

    /**
     * @return list<string>
     */
    protected function lines(SharedAddress $address): array
    {
        $place = fn (string $level) => $address->location_snapshot[$level]['en'] ?? null;

        return array_values(array_filter([
            $address->detailed_address,
            implode(', ', array_filter([$place('union'), $place('upazila')])),
            implode(', ', array_filter([$place('district'), $place('division')])).($address->postcode ? ' '.$address->postcode : ''),
        ], fn (?string $line) => $line !== null && trim($line) !== ''));
    }

    protected function sharedAddress(BusinessAccount $account, ClientAddressType $type): ?SharedAddress
    {
        /** @var SharedAddress|null $address */
        $address = SharedAddress::query()
            ->where('owner_type', AddressOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('type', $type->value)
            ->where('status', AddressStatus::Active->value)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();

        return $address;
    }
}
