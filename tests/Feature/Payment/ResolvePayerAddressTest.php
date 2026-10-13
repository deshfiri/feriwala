<?php

use App\Domain\Account\Enums\AddressType;
use App\Domain\Account\Models\UserAddress;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Billing\Actions\ResolvePayerAddress;

function payerAddressSaveShared(int $accountId, ?string $postcode): void
{
    $chain = addressTestLocationChain();

    app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $accountId,
        type: ClientAddressType::Business->value,
        contactName: 'Karim Traders',
        contactMobile: '+8801711111111',
        divisionId: $chain['division']->id,
        districtId: $chain['district']->id,
        upazilaId: $chain['upazila']->id,
        unionId: null,
        detailedAddress: 'House 1, Road 2',
        landmark: null,
        postcode: $postcode,
    );
}

it('builds the payer address from the account\'s shared business address', function () {
    $account = testBusinessAccount();
    payerAddressSaveShared($account->id, '1207');

    $address = app(ResolvePayerAddress::class)->handle($account);

    expect($address)->not->toBeNull()
        ->and($address->line)->toBe('House 1, Road 2')
        ->and($address->city)->toBe('Test District')
        ->and($address->state)->toBe('Test Division')
        ->and($address->postcode)->toBe('1207')
        ->and($address->country)->toBe('BD');
});

it('falls back to the owner\'s billing address when no shared address is complete', function () {
    $account = testBusinessAccount();
    payerAddressSaveShared($account->id, null);

    UserAddress::query()->create([
        'user_id' => $account->owner->id,
        'type' => AddressType::Billing,
        'contact_name' => 'Karim',
        'contact_mobile' => '+8801711111111',
        'line_1' => '12 Billing Lane',
        'city' => 'Chattogram',
        'district' => 'Chattogram',
        'postcode' => '4000',
        'country' => 'bd',
    ]);

    $address = app(ResolvePayerAddress::class)->handle($account);

    expect($address->line)->toBe('12 Billing Lane')
        ->and($address->country)->toBe('BD');
});

it('returns nothing rather than inventing an address', function () {
    $account = testBusinessAccount();
    payerAddressSaveShared($account->id, null);

    expect(app(ResolvePayerAddress::class)->handle($account))->toBeNull()
        ->and(app(ResolvePayerAddress::class)->handle(null))->toBeNull();
});
