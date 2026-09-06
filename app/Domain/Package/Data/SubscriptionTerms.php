<?php

namespace App\Domain\Package\Data;

use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Package\Models\Package;
use App\Domain\Package\Models\PackageCharge;

/**
 * What an account actually bought (§8.1, §8.3).
 *
 * A copy of the package as it stood the moment the subscription was created,
 * not a reference to it. Without this, an administrator lowering a staff limit
 * changes what every existing subscriber is entitled to — retroactively, and
 * without anyone agreeing to it. §8.3 makes a change of terms an upgrade or a
 * renewal, which is something an account accepts; it is not something that
 * happens to them between one request and the next.
 *
 * It carries the terms an invoice or a renewal quote reads back as well as the
 * entitlements, because "what did this account buy" and "what does it grant"
 * are the same question asked twice, and answering them from different places
 * is how they come to disagree.
 */
readonly class SubscriptionTerms
{
    /**
     * @param  array<string, bool|int|string|null>  $features  keyed by PackageFeature
     * @param  array<int, array{charge_type: string, amount_minor: int, frequency: string}>  $charges
     */
    public function __construct(
        public string $packagePublicId,
        public string $slug,
        public string $name,
        public int $feeMinor,
        public ?int $registrationFeeMinor,
        public ?int $renewalFeeMinor,
        public ?string $renewalFrequency,
        public ?int $validityDays,
        public ?int $gracePeriodDays,
        public int $requiredDepositMinor,
        public int $minimumBalanceMinor,
        public string $currencyCode,
        public array $features,
        public array $charges,
    ) {}

    /**
     * Take the snapshot.
     */
    public static function capture(Package $package): self
    {
        $features = [];

        foreach (PackageFeature::cases() as $feature) {
            // The resolved value, not the raw row: a package that says nothing
            // about a feature has a default (§8.1), and the subscription should
            // record what it was actually granted rather than a silence that a
            // later change of default could reinterpret.
            $features[$feature->value] = $package->feature($feature);
        }

        return new self(
            packagePublicId: $package->public_id,
            slug: $package->slug,
            name: $package->name,
            feeMinor: $package->fee_minor->minorUnits,
            registrationFeeMinor: $package->registration_fee_minor?->minorUnits,
            renewalFeeMinor: $package->renewal_fee_minor?->minorUnits,
            renewalFrequency: $package->renewal_frequency,
            validityDays: $package->validity_days,
            gracePeriodDays: $package->grace_period_days,
            requiredDepositMinor: $package->required_deposit_minor->minorUnits,
            minimumBalanceMinor: $package->minimum_balance_minor->minorUnits,
            currencyCode: $package->currency_code,
            features: $features,
            charges: $package->charges->map(fn (PackageCharge $charge) => [
                'charge_type' => (string) $charge->charge_type,
                'amount_minor' => $charge->amount_minor->minorUnits,
                'frequency' => (string) $charge->frequency,
            ])->values()->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        /** @var array<string, bool|int|string|null> $features */
        $features = $stored['features'] ?? [];

        /** @var array<int, array{charge_type: string, amount_minor: int, frequency: string}> $charges */
        $charges = $stored['charges'] ?? [];

        return new self(
            packagePublicId: (string) ($stored['package_public_id'] ?? ''),
            slug: (string) ($stored['slug'] ?? ''),
            name: (string) ($stored['name'] ?? ''),
            feeMinor: (int) ($stored['fee_minor'] ?? 0),
            registrationFeeMinor: isset($stored['registration_fee_minor'])
                ? (int) $stored['registration_fee_minor']
                : null,
            renewalFeeMinor: isset($stored['renewal_fee_minor'])
                ? (int) $stored['renewal_fee_minor']
                : null,
            renewalFrequency: $stored['renewal_frequency'] ?? null,
            validityDays: isset($stored['validity_days']) ? (int) $stored['validity_days'] : null,
            gracePeriodDays: isset($stored['grace_period_days']) ? (int) $stored['grace_period_days'] : null,
            requiredDepositMinor: (int) ($stored['required_deposit_minor'] ?? 0),
            minimumBalanceMinor: (int) ($stored['minimum_balance_minor'] ?? 0),
            currencyCode: (string) ($stored['currency_code'] ?? 'BDT'),
            features: $features,
            charges: $charges,
        );
    }

    /**
     * The entitlement this subscription was granted.
     *
     * Null means unlimited for a limit, exactly as a live package would say —
     * so a caller cannot tell whether it is reading a snapshot, which is the
     * point.
     */
    public function feature(PackageFeature $feature): bool|int|string|null
    {
        if (! array_key_exists($feature->value, $this->features)) {
            // A feature added to the enum after this subscription was taken.
            // Its default is the honest answer: nobody sold it to this account.
            return $feature->default();
        }

        return $this->features[$feature->value];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package_public_id' => $this->packagePublicId,
            'slug' => $this->slug,
            'name' => $this->name,
            'fee_minor' => $this->feeMinor,
            'registration_fee_minor' => $this->registrationFeeMinor,
            'renewal_fee_minor' => $this->renewalFeeMinor,
            'renewal_frequency' => $this->renewalFrequency,
            'validity_days' => $this->validityDays,
            'grace_period_days' => $this->gracePeriodDays,
            'required_deposit_minor' => $this->requiredDepositMinor,
            'minimum_balance_minor' => $this->minimumBalanceMinor,
            'currency_code' => $this->currencyCode,
            'features' => $this->features,
            'charges' => $this->charges,
        ];
    }
}
