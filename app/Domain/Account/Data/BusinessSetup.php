<?php

namespace App\Domain\Account\Data;

/**
 * Onboarding step 6 (D39): only what is needed to establish the online store.
 *
 * A null business mobile or email means "use my own"; a null address means
 * none was given. The domain is a preference saved for staff to check and buy
 * by hand — never bought automatically (D9).
 */
final readonly class BusinessSetup
{
    /**
     * @param  array{division_id: int, district_id: int, upazila_id: int, union_id: int|null, detailed_address: string, postcode: string|null}|null  $address
     */
    public function __construct(
        public string $businessName,
        public string $domainName,
        public string $domainExtension,
        public ?string $businessMobile,
        public ?string $businessEmail,
        public ?array $address,
    ) {}
}
