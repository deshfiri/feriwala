<?php

use App\Domain\Address\Actions\ArchiveSharedAddress;
use App\Domain\Address\Actions\SaveSharedAddress;
use App\Domain\Address\Actions\SetDefaultSharedAddress;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Enums\ClientAddressType;
use App\Domain\Address\Enums\SupplierAddressType;
use App\Domain\Supplier\Models\Supplier;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;

it('resolves and freezes the bilingual location snapshot at save time', function () {
    $account = testBusinessAccount();
    $chain = addressTestLocationChain();

    $address = app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: ClientAddressType::Business->value,
        contactName: 'Karim Traders',
        contactMobile: '+8801711111111',
        divisionId: $chain['division']->id,
        districtId: $chain['district']->id,
        upazilaId: $chain['upazila']->id,
        unionId: $chain['union']->id,
        detailedAddress: 'House 1, Road 2',
        landmark: 'Near the mosque',
        postcode: '1207',
    );

    expect($address->location_snapshot)->toBe([
        'division' => ['en' => 'Test Division', 'bn' => 'টেস্ট বিভাগ'],
        'district' => ['en' => 'Test District', 'bn' => 'টেস্ট জেলা'],
        'upazila' => ['en' => 'Test Upazila', 'bn' => 'টেস্ট উপজেলা'],
        'union' => ['en' => 'Test Union', 'bn' => 'টেস্ট ইউনিয়ন'],
    ]);

    // Renaming the location afterwards must not change the address's own record.
    $chain['upazila']->forceFill(['name_en' => 'Renamed Upazila'])->save();

    expect($address->refresh()->toSnapshot()->locationSnapshot['upazila']['en'])->toBe('Test Upazila');
});

it('allows a union to be omitted', function () {
    $account = testBusinessAccount();
    $chain = addressTestLocationChain();

    $address = app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: ClientAddressType::Operational->value,
        contactName: 'Karim Traders',
        contactMobile: '+8801711111111',
        divisionId: $chain['division']->id,
        districtId: $chain['district']->id,
        upazilaId: $chain['upazila']->id,
        unionId: null,
        detailedAddress: 'Warehouse 4',
        landmark: null,
        postcode: null,
    );

    expect($address->union_id)->toBeNull()
        ->and($address->location_snapshot['union'])->toBeNull();
});

it('enforces one default address per owner and type, independently of other types', function () {
    $account = testBusinessAccount();

    $business1 = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id, ['type' => ClientAddressType::Business->value, 'makeDefault' => true]);
    $business2 = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id, ['type' => ClientAddressType::Business->value, 'makeDefault' => true]);
    $operational = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id, ['type' => ClientAddressType::Operational->value, 'makeDefault' => true]);

    expect($business1->refresh()->is_default)->toBeFalse()
        ->and($business2->refresh()->is_default)->toBeTrue()
        ->and($operational->refresh()->is_default)->toBeTrue();
});

it('does not touch a different owner\'s default when setting one', function () {
    $accountA = testBusinessAccount();
    $accountB = testBusinessAccount();

    $addressA = addressTestCreate(AddressOwnerType::BusinessAccount, $accountA->id, ['makeDefault' => true]);
    $addressB = addressTestCreate(AddressOwnerType::BusinessAccount, $accountB->id, ['makeDefault' => true]);

    app(SetDefaultSharedAddress::class)->handle($addressA->refresh());

    expect($addressA->refresh()->is_default)->toBeTrue()
        ->and($addressB->refresh()->is_default)->toBeTrue();
});

it('archives an address, clears its default flag, and refuses further edits', function () {
    $account = testBusinessAccount();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id, ['makeDefault' => true]);

    $archived = app(ArchiveSharedAddress::class)->handle($address);

    expect($archived->status)->toBe(AddressStatus::Archived)
        ->and($archived->is_default)->toBeFalse();

    $chain = addressTestLocationChain();

    app(SaveSharedAddress::class)->handle(
        ownerType: AddressOwnerType::BusinessAccount,
        ownerId: $account->id,
        type: ClientAddressType::Business->value,
        contactName: 'Changed',
        contactMobile: '+8801711111111',
        divisionId: $chain['division']->id,
        districtId: $chain['district']->id,
        upazilaId: $chain['upazila']->id,
        unionId: null,
        detailedAddress: 'Changed',
        landmark: null,
        postcode: null,
        existing: $archived,
    );
})->throws(RuntimeException::class, 'An archived address cannot be edited.');

it('refuses to archive an already-archived address', function () {
    $account = testBusinessAccount();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    app(ArchiveSharedAddress::class)->handle($address);

    app(ArchiveSharedAddress::class)->handle($address->refresh());
})->throws(IllegalStateTransition::class);

it('refuses to make an archived address the default', function () {
    $account = testBusinessAccount();
    $address = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    app(ArchiveSharedAddress::class)->handle($address);

    app(SetDefaultSharedAddress::class)->handle($address->refresh());
})->throws(RuntimeException::class, 'An archived address cannot be made the default.');

it('holds a Supplier-owned address independently of any BusinessAccount', function () {
    $supplier = Supplier::factory()->create();

    $address = addressTestCreate(AddressOwnerType::Supplier, $supplier->id, ['type' => SupplierAddressType::Pickup->value]);

    expect($address->owner_type)->toBe(AddressOwnerType::Supplier->value)
        ->and($address->ownedBy(AddressOwnerType::Supplier, $supplier->id))->toBeTrue()
        ->and($address->ownedBy(AddressOwnerType::BusinessAccount, $supplier->id))->toBeFalse();
});
