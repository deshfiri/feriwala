<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Order\Models\Order;
use App\Integrations\Payment\Data\PayerAddress;

/**
 * The postal address a gateway that requires one is given for a payer.
 *
 * One answer from every address the payer has already given, best first:
 *
 *   1. the billing, then shipping, address the order was placed with — what the
 *      payer typed on the checkout page a moment ago;
 *   2. the Partner portal's address book (business, then operational);
 *   3. the owner's saved billing, shipping and present addresses.
 *
 * The first one with every part a gateway insists on wins. Nothing is ever
 * defaulted or invented: when none is complete the answer is `null`, and
 * {@see missing()} names what the most relevant address still lacks so the
 * payer is told exactly what to add.
 */
class ResolvePayerAddress
{
    /** What a gateway needs, with the label shown to the payer. */
    protected const PARTS = [
        'line' => 'street address',
        'city' => 'city',
        'state' => 'division or district',
        'postcode' => 'postcode',
        'country' => 'country',
    ];

    public function handle(?BusinessAccount $account, ?Order $order = null): ?PayerAddress
    {
        foreach ($this->candidates($account, $order) as $candidate) {
            if ($this->missingFrom($candidate) === []) {
                /** @var array{line: string, city: string, state: string, postcode: string, country: string} $parts */
                $parts = array_map(fn (mixed $part): string => trim((string) $part), $candidate);

                return new PayerAddress(...$parts);
            }
        }

        return null;
    }

    /**
     * What the most relevant address on file still lacks, as plain labels.
     *
     * @return list<string>
     */
    public function missing(?BusinessAccount $account, ?Order $order = null): array
    {
        foreach ($this->candidates($account, $order) as $candidate) {
            return $this->missingFrom($candidate);
        }

        return array_values(self::PARTS);
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    protected function missingFrom(array $candidate): array
    {
        $missing = [];

        foreach (self::PARTS as $key => $label) {
            $value = $candidate[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /**
     * Every address the payer has given, best first, as the five parts.
     *
     * @return iterable<array{line: mixed, city: mixed, state: mixed, postcode: mixed, country: mixed}>
     */
    protected function candidates(?BusinessAccount $account, ?Order $order): iterable
    {
        foreach ([$order?->billing_address, $order?->shipping_address] as $snapshot) {
            if (is_array($snapshot) && $snapshot !== []) {
                yield $this->fromFlat($snapshot);
            }
        }

        if ($account === null) {
            return;
        }

        $shared = SharedAddress::query()
            ->where('owner_type', AddressOwnerType::BusinessAccount->value)
            ->where('owner_id', $account->id)
            ->where('status', AddressStatus::Active->value)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        foreach ($shared as $address) {
            yield [
                'line' => $address->detailed_address,
                'city' => $address->location_snapshot['district']['en'] ?? null,
                'state' => $address->location_snapshot['division']['en'] ?? null,
                'postcode' => $address->postcode,
                'country' => 'BD',
            ];
        }

        if ($account->owner === null) {
            return;
        }

        foreach ([AddressType::Billing, AddressType::Shipping, AddressType::Present] as $type) {
            foreach (UserAddress::query()->currentFor($account->owner->id, $type)->get() as $address) {
                yield $this->fromFlat($address->toSnapshot());
            }
        }
    }

    /**
     * A checkout address (`line_1`, `city`, `district`, `postcode`, `country`).
     *
     * A city left blank falls back to the district it sits in, and the other
     * way round — both are real places the payer named, not placeholders.
     *
     * @param  array<array-key, mixed>  $flat
     * @return array{line: mixed, city: mixed, state: mixed, postcode: mixed, country: mixed}
     */
    protected function fromFlat(array $flat): array
    {
        $city = $this->text($flat['city'] ?? null) ?? $this->text($flat['district'] ?? null);
        $state = $this->text($flat['district'] ?? null) ?? $this->text($flat['city'] ?? null);

        return [
            'line' => $flat['line_1'] ?? null,
            'city' => $city,
            'state' => $state,
            'postcode' => $flat['postcode'] ?? null,
            'country' => $this->countryCode($this->text($flat['country'] ?? null)),
        ];
    }

    protected function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    protected function countryCode(?string $country): ?string
    {
        return match (mb_strtolower((string) $country)) {
            '' => null,
            'bangladesh', 'bd' => 'BD',
            default => mb_strlen((string) $country) === 2 ? mb_strtoupper((string) $country) : null,
        };
    }
}
