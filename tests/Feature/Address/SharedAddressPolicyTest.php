<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Models\SharedAddress;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets an account owner view, create, update and archive their own account\'s addresses', function () {
    $account = testBusinessAccount();
    $owner = $account->owner;
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    expect($owner->can('viewAny', SharedAddress::class))->toBeTrue()
        ->and($owner->can('view', $address))->toBeTrue()
        ->and($owner->can('create', SharedAddress::class))->toBeTrue()
        ->and($owner->can('update', $address))->toBeTrue()
        ->and($owner->can('archive', $address))->toBeTrue()
        ->and($owner->can('setDefault', $address))->toBeTrue();
});

it('refuses a different account\'s owner entirely — the address is not merely unauthorised, it does not exist to them', function () {
    $account = testBusinessAccount();
    $otherAccount = testBusinessAccount();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    $stranger = $otherAccount->owner;

    expect($stranger->can('view', $address))->toBeFalse()
        ->and($stranger->can('update', $address))->toBeFalse()
        ->and($stranger->can('archive', $address))->toBeFalse();
});

it('refuses a plain staff member the create/update/archive abilities, but not view', function () {
    $account = testBusinessAccount();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    $staff = User::factory()->create();
    $account->memberships()->create(['user_id' => $staff->id, 'role' => AccountRole::Staff]);

    expect($staff->can('view', $address))->toBeTrue()
        ->and($staff->can('create', SharedAddress::class))->toBeFalse()
        ->and($staff->can('update', $address))->toBeFalse()
        ->and($staff->can('archive', $address))->toBeFalse();
});

it('refuses platform staff with no business account of their own', function () {
    // Not SuperAdmin: that role passes every Gate check by design
    // (AuthorizationServiceProvider), which is not this policy's concern to
    // contest — a role with no address-related permission is the real test.
    $account = testBusinessAccount();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    $staff = testPlatformStaff(PlatformRole::KycManager);

    expect($staff->can('view', $address))->toBeFalse();
});
