<?php

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Wholesale\Actions\UseSavedAddressForCheckout;
use App\Domain\Wholesale\Exceptions\CheckoutRefused;

/*
 * The Partner's saved addresses are the one source for checkout: choosing one
 * sets billing and delivery together, and only the account's own can be chosen.
 */

function checkoutSavedAddress(int $accountId, string $line = 'House 1, Road 2', ?string $postcode = '1207'): string
{
    $chain = addressTestLocationChain();

    return app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $accountId,
        type: ClientAddressType::Business->value,
        contactName: 'Karim Traders',
        contactMobile: '+8801711111111',
        divisionId: $chain['division']->id,
        districtId: $chain['district']->id,
        upazilaId: $chain['upazila']->id,
        unionId: null,
        detailedAddress: $line,
        landmark: 'Near the mosque',
        postcode: $postcode,
    )->public_id;
}

it('sets billing and delivery from the chosen saved address', function () {
    $account = testBusinessAccount();
    $id = checkoutSavedAddress($account->id);

    app(UseSavedAddressForCheckout::class)->handle($account->owner, $account, $id);

    foreach ([AddressType::Billing, AddressType::Shipping] as $type) {
        $saved = UserAddress::query()->currentFor($account->owner->id, $type)->firstOrFail();

        expect($saved->line_1)->toBe('House 1, Road 2')
            ->and($saved->line_2)->toBe('Near the mosque')
            ->and($saved->city)->toBe('Test Upazila')
            ->and($saved->district)->toBe('Test District')
            ->and($saved->postcode)->toBe('1207')
            ->and($saved->contact_name)->toBe('Karim Traders');
    }
});

it('refuses an address that belongs to another account', function () {
    $mine = testBusinessAccount();
    $theirs = testBusinessAccount();
    $id = checkoutSavedAddress($theirs->id);

    expect(fn () => app(UseSavedAddressForCheckout::class)->handle($mine->owner, $mine, $id))
        ->toThrow(CheckoutRefused::class);

    expect(UserAddress::query()->where('user_id', $mine->owner->id)->count())->toBe(0);
});

it('refuses an address that is not saved at all', function () {
    $account = testBusinessAccount();

    expect(fn () => app(UseSavedAddressForCheckout::class)->handle($account->owner, $account, 'nope'))
        ->toThrow(CheckoutRefused::class);
});
