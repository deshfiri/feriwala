<?php

namespace App\Domain\Account\Actions;

use App\Domain\Account\Data\BusinessSetup;
use App\Domain\Account\Exceptions\OnboardingRefused;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\OnboardingApplication;
use App\Domain\Account\PreferredDomainExtensions;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Audit\Actions\RecordAuditLog;
use App\Domain\Audit\Data\AuditEntry;
use Illuminate\Database\DatabaseManager;

/**
 * Onboarding step 6 (D39): the business or shop name, the preferred domain, and
 * — optionally — a business mobile, email and address.
 *
 * The domain is a preference only: a name and one of the extensions the admin
 * has enabled, saved with the application for staff to check and buy by hand.
 * There is no availability check and nothing is bought here (D39, D9). A
 * business mobile or email left out means the owner's own is used.
 */
class SaveBusinessSetup
{
    public function __construct(
        protected OnboardingApplication $application,
        protected PreferredDomainExtensions $extensions,
        protected SaveSharedAddress $sharedAddresses,
        protected RecordAuditLog $audit,
        protected DatabaseManager $database,
    ) {}

    /**
     * @throws OnboardingRefused
     */
    public function handle(BusinessAccount $account, BusinessSetup $setup): BusinessAccount
    {
        $extension = PreferredDomainExtensions::normalise($setup->domainExtension);

        if ($extension === null || ! $this->extensions->allows($extension)) {
            throw OnboardingRefused::because('extension_not_offered', 'domain_extension');
        }

        $name = mb_strtolower(trim($setup->domainName));

        if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) !== 1) {
            throw OnboardingRefused::because('domain_name_invalid', 'domain_name');
        }

        return $this->database->transaction(function () use ($account, $setup, $extension, $name): BusinessAccount {
            /** @var BusinessAccount $locked */
            $locked = BusinessAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->application->assertEditable($locked);

            $owner = $locked->owner ?? throw OnboardingRefused::because('not_onboarding');
            $existingAddress = $this->application->businessAddress($locked);

            if ($setup->address !== null) {
                $this->sharedAddresses->handle(
                    ownerType: AddressOwnerType::BusinessAccount,
                    ownerId: $locked->id,
                    type: ClientAddressType::Business->value,
                    contactName: trim($setup->businessName),
                    contactMobile: $setup->businessMobile ?? (string) $owner->mobile,
                    divisionId: $setup->address['division_id'],
                    districtId: $setup->address['district_id'],
                    upazilaId: $setup->address['upazila_id'],
                    unionId: $setup->address['union_id'],
                    detailedAddress: trim($setup->address['detailed_address']),
                    landmark: null,
                    postcode: $setup->address['postcode'],
                    makeDefault: true,
                    existing: $existingAddress,
                );
            }

            // A staff check vouches only for the domain it looked at.
            $domainChanged = $locked->preferredDomain() !== $name.$extension;

            $locked->forceFill([
                ...($domainChanged ? [
                    'preferred_domain_check_result' => null,
                    'preferred_domain_checked_at' => null,
                    'preferred_domain_checked_by' => null,
                    'preferred_domain_check_note' => null,
                ] : []),
                'name' => trim($setup->businessName),
                'business_mobile' => $setup->businessMobile,
                'business_email' => $setup->businessEmail === null ? null : mb_strtolower(trim($setup->businessEmail)),
                'preferred_domain_name' => $name,
                'preferred_domain_extension' => $extension,
                'business_setup_completed_at' => now(),
                'correction_sections' => $this->without($locked->correction_sections, ['business', 'domain']),
            ])->save();

            $this->audit->handle(new AuditEntry(
                action: 'onboarding.business_setup_saved',
                actorId: $owner->id,
                auditableType: BusinessAccount::class,
                auditableId: $locked->id,
                after: [
                    'business_name' => $locked->name,
                    'preferred_domain' => $locked->preferredDomain(),
                    'business_mobile_given' => $setup->businessMobile !== null,
                    'business_email_given' => $setup->businessEmail !== null,
                    'business_address_given' => $setup->address !== null,
                ],
                accountId: $locked->id,
                module: 'account',
            ));

            return $locked;
        });
    }

    /**
     * @param  list<string>|null  $sections
     * @param  list<string>  $remove
     * @return list<string>|null
     */
    protected function without(?array $sections, array $remove): ?array
    {
        $kept = array_values(array_diff($sections ?? [], $remove));

        return $kept === [] ? null : $kept;
    }
}
