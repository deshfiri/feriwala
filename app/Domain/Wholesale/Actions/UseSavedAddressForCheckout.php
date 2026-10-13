<?php

namespace App\Domain\Wholesale\Actions;

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Address\Queries\SharedAddressesForOwner;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;
use App\Models\User;

/**
 * Check out with one of the Partner's own saved addresses.
 *
 * The Addresses tab is the single place an address is kept. Choosing one there
 * becomes both the billing and the delivery address of this checkout — the order
 * still takes its own snapshot of it, so editing the saved address later never
 * rewrites a past order. Scoped to the signed-in account's own address book: an
 * identifier belonging to anyone else finds nothing.
 */
class UseSavedAddressForCheckout
{
    public function __construct(
        protected SharedAddressesForOwner $addresses,
        protected SaveCheckoutAddress $save,
    ) {}

    /**
     * @throws CheckoutRefused when the address is not an active one of this account's
     */
    public function handle(User $person, BusinessAccount $account, string $publicId): void
    {
        $address = $this->addresses->findForOwner(AddressOwnerType::BusinessAccount, $account->id, $publicId);

        if ($address === null || $address->status !== AddressStatus::Active) {
            throw new CheckoutRefused(__('wholesale.checkout.address.not_available'), 'address');
        }

        $fields = $this->fieldsFor($address);

        $this->save->handle($person, AddressType::Billing, $fields);
        $this->save->handle($person, AddressType::Shipping, $fields);
    }

    /**
     * A saved address as the checkout keeps it.
     *
     * @return array<string, string|null>
     */
    public function fieldsFor(SharedAddress $address): array
    {
        $place = fn (string $level): ?string => $address->location_snapshot[$level]['en'] ?? null;

        return [
            'contact_name' => $address->contact_name,
            'contact_mobile' => $address->contact_mobile,
            'line_1' => $address->detailed_address,
            'line_2' => $address->landmark,
            'area' => $place('union'),
            'city' => $place('upazila') ?? $place('district'),
            'district' => $place('district'),
            'postcode' => $address->postcode,
        ];
    }
}
