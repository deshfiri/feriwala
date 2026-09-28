<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Models\User;

function erpAddressTestPayload(array $chain, array $overrides = []): array
{
    return [
        'type' => ClientAddressType::Business->value,
        'contact_name' => 'Karim Traders',
        'contact_mobile' => '+8801711111111',
        'division_id' => $chain['division']->id,
        'district_id' => $chain['district']->id,
        'upazila_id' => $chain['upazila']->id,
        'union_id' => $chain['union']->id,
        'detailed_address' => 'House 1, Road 2',
        ...$overrides,
    ];
}

it('lists, creates, edits, sets default and archives an address end-to-end', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $owner = $account->owner;
    $chain = addressTestLocationChain();

    $this->actingAs($owner)->get(route('addresses.index'))->assertOk();

    $this->actingAs($owner)
        ->post(route('addresses.store'), erpAddressTestPayload($chain, ['is_default' => true]))
        ->assertRedirect();

    $address = SharedAddress::query()->firstOrFail();
    expect($address->is_default)->toBeTrue()
        ->and($address->owner_id)->toBe($account->id);

    $this->actingAs($owner)
        ->put(route('addresses.update', $address->public_id), erpAddressTestPayload($chain, [
            'contact_name' => 'Karim Traders Ltd',
            'union_id' => null,
        ]))
        ->assertRedirect();

    expect($address->refresh()->contact_name)->toBe('Karim Traders Ltd')
        ->and($address->union_id)->toBeNull();

    $second = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id, [
        'type' => ClientAddressType::Business->value,
    ]);

    $this->actingAs($owner)
        ->post(route('addresses.default', $second->public_id))
        ->assertRedirect();

    expect($second->refresh()->is_default)->toBeTrue()
        ->and($address->refresh()->is_default)->toBeFalse();

    $this->actingAs($owner)
        ->post(route('addresses.archive', $second->public_id))
        ->assertRedirect();

    expect($second->refresh()->status)->toBe(AddressStatus::Archived)
        ->and($second->is_default)->toBeFalse();
});

it('refuses to reach another account\'s address (§31.3)', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $other = testBusinessAccount(AccountStatus::Active);
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $other->id);
    $chain = addressTestLocationChain();

    $this->actingAs($account->owner)
        ->put(route('addresses.update', $address->public_id), erpAddressTestPayload($chain))
        ->assertNotFound();

    $this->actingAs($account->owner)
        ->post(route('addresses.archive', $address->public_id))
        ->assertNotFound();
});

it('refuses a plain staff member the create ability', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $staff = User::factory()->create();
    $account->memberships()->create(['user_id' => $staff->id, 'role' => AccountRole::Staff]);
    $chain = addressTestLocationChain();

    $this->actingAs($staff)
        ->post(route('addresses.store'), erpAddressTestPayload($chain))
        ->assertForbidden();
});

it('rejects a union that does not belong to the chosen upazila', function () {
    $account = testBusinessAccount(AccountStatus::Active);
    $chain = addressTestLocationChain();
    $otherChain = addressTestLocationChain();

    $this->actingAs($account->owner)
        ->post(route('addresses.store'), erpAddressTestPayload($chain, ['union_id' => $otherChain['union']->id]))
        ->assertInvalid(['union_id']);
});
