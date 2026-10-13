<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Integrations\Payment\Data\PayerAddress;

/**
 * The postal address a gateway that requires one is given for a payer.
 *
 * Read from what the account already holds, in order: its shared business
 * address book (default first), then the owner's billing address, then their
 * present address. An address missing any part a gateway insists on is skipped
 * rather than patched — nothing is ever fabricated, so when none qualifies the
 * answer is `null` and the gateway refuses to start.
 */
class ResolvePayerAddress
{
    public function handle(?BusinessAccount $account): ?PayerAddress
    {
        if ($account === null) {
            return null;
        }

        return $this->fromSharedAddresses($account) ?? $this->fromOwnerAddresses($account);
    }

    private function fromSharedAddresses(BusinessAccount $account): ?PayerAddress
    {
        $addresses = SharedAddress::query()
            ->where('owner_type', AddressOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('status', AddressStatus::Active->value)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        foreach ($addresses as $address) {
            $resolved = $this->build(
                line: $address->detailed_address,
                city: $address->location_snapshot['district']['en'] ?? null,
                state: $address->location_snapshot['division']['en'] ?? null,
                postcode: $address->postcode,
                country: 'BD',
            );

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function fromOwnerAddresses(BusinessAccount $account): ?PayerAddress
    {
        if ($account->owner === null) {
            return null;
        }

        foreach ([AddressType::Billing, AddressType::Present] as $type) {
            $addresses = UserAddress::query()->currentFor($account->owner->id, $type)->get();

            foreach ($addresses as $address) {
                $resolved = $this->build(
                    line: $address->line_1,
                    city: $address->city,
                    state: $address->district,
                    postcode: $address->postcode,
                    country: $this->countryCode($address->country),
                );

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function build(mixed $line, mixed $city, mixed $state, mixed $postcode, mixed $country): ?PayerAddress
    {
        $parts = array_map(
            fn (mixed $part): string => is_string($part) ? trim($part) : '',
            [$line, $city, $state, $postcode, $country],
        );

        if (in_array('', $parts, true)) {
            return null;
        }

        return new PayerAddress(...$parts);
    }

    private function countryCode(?string $country): ?string
    {
        $country = $country === null ? '' : trim($country);

        return match (mb_strtolower($country)) {
            '' => null,
            'bangladesh', 'bd' => 'BD',
            default => mb_strlen($country) === 2 ? mb_strtoupper($country) : null,
        };
    }
}
