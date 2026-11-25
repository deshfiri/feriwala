<?php

namespace App\Domain\Account\Data;

/**
 * Onboarding step 5 (D39): who the owner is, as on their ID, and where they live.
 *
 * A Bangladeshi residence is the structured BD location chain (division,
 * district, upazila, union) with the street; anywhere else, the free-text
 * address the platform already keeps.
 */
final readonly class PersonalDetails
{
    /**
     * @param  array{division_id: int, district_id: int, upazila_id: int, union_id: int|null, detailed_address: string, postcode: string|null}|null  $bdAddress
     * @param  array{line_1: string, line_2: string|null, city: string, postcode: string|null}|null  $foreignAddress
     */
    public function __construct(
        public string $legalName,
        public string $country,
        public string $nationality,
        public string $dateOfBirth,
        public ?array $bdAddress,
        public ?array $foreignAddress,
    ) {}

    public function livesInBangladesh(): bool
    {
        return strtoupper($this->country) === 'BD';
    }
}
