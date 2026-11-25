<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\PersonalDetails;
use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use App\Support\Localization\InternationalMobile;
use Illuminate\Database\DatabaseManager;

/**
 * Onboarding step 5 (D39): the owner's legal name, country of residence,
 * nationality, date of birth and residential address.
 *
 * A Bangladeshi residence goes into the shared address book as the owner's
 * residential address, on the structured BD locations; anywhere else, into the
 * free-text address the platform already keeps for a person. The step is then
 * marked complete, and a correction a reviewer asked for on it is cleared.
 */
class SavePersonalDetails
{
    public function __construct(
        protected OnboardingApplication $application,
        protected SaveSharedAddress $sharedAddresses,
        protected InternationalMobile $mobiles,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws OnboardingRefused
     */
    public function handle(BusinessAccount $account, PersonalDetails $details): BusinessAccount
    {
        return $this->database->transaction(function () use ($account, $details): BusinessAccount {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->application->assertEditable($locked);

            $owner = $locked->owner ?? throw OnboardingRefused::because('not_onboarding');

            $owner->forceFill([
                'name' => trim($details->legalName),
                'country' => strtoupper($details->country),
                'nationality' => strtoupper($details->nationality),
                'date_of_birth' => $details->dateOfBirth,
            ])->save();

            if ($details->livesInBangladesh() && $details->bdAddress !== null) {
                $contactMobile = $this->mobiles->normalise('BD', (string) $owner->mobile)
                    ?? (str_starts_with((string) $owner->mobile, '+') ? (string) $owner->mobile : null)
                    ?? throw OnboardingRefused::because('mobile_unreadable', 'mobile');

                $this->sharedAddresses->handle(
                    ownerType: AddressOwnerType::BusinessAccount,
                    ownerId: $locked->id,
                    type: ClientAddressType::Residential->value,
                    contactName: $owner->name,
                    contactMobile: $contactMobile,
                    divisionId: $details->bdAddress['division_id'],
                    districtId: $details->bdAddress['district_id'],
                    upazilaId: $details->bdAddress['upazila_id'],
                    unionId: $details->bdAddress['union_id'],
                    detailedAddress: trim($details->bdAddress['detailed_address']),
                    landmark: null,
                    postcode: $details->bdAddress['postcode'],
                    makeDefault: true,
                    existing: $this->application->residentialAddress($locked),
                );
            } elseif ($details->foreignAddress !== null) {
                $existing = $this->application->foreignAddress($locked);
                $attributes = [
                    'contact_name' => $owner->name,
                    'contact_mobile' => $owner->mobile,
                    'line_1' => trim($details->foreignAddress['line_1']),
                    'line_2' => $details->foreignAddress['line_2'],
                    'city' => trim($details->foreignAddress['city']),
                    'postcode' => $details->foreignAddress['postcode'],
                    'country' => strtoupper($details->country),
                ];

                $existing === null
                    ? UserAddress::create([...$attributes, 'user_id' => $owner->id, 'type' => AddressType::Present, 'is_default' => true])
                    : $existing->forceFill($attributes)->save();
            } else {
                throw OnboardingRefused::because('address_required', 'address');
            }

            $locked->forceFill([
                'personal_details_completed_at' => now(),
                'correction_sections' => $this->without($locked->correction_sections, 'personal'),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'onboarding.personal_details_saved',
                actorId: $owner->id,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                // Which facts were given — never the name, date of birth or address themselves.
                after: ['residence' => strtoupper($details->country), 'address_kind' => $details->livesInBangladesh() ? 'structured' : 'free_text'],
                accountId: $locked->id,
                module: 'account',
            ));

            return $locked;
        });
    }

    /**
     * @param  list<string>|null  $sections
     * @return list<string>|null
     */
    protected function without(?array $sections, string $section): ?array
    {
        $kept = array_values(array_diff($sections ?? [], [$section]));

        return $kept === [] ? null : $kept;
    }
}
