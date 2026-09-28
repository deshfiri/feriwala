<?php

use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Address\Enums\AddressStatus;
use App\Domain\Address\Enums\SupplierAddressType;
use App\Domain\Address\Models\SharedAddress;
use App\Domain\Supplier\Models\Supplier;

function supplierAddressTestPayload(array $chain, array $overrides = []): array
{
    return [
        'type' => SupplierAddressType::Pickup->value,
        'contact_name' => 'Warehouse Manager',
        'contact_mobile' => '+8801711111111',
        'division_id' => $chain['division']->id,
        'district_id' => $chain['district']->id,
        'upazila_id' => $chain['upazila']->id,
        'union_id' => $chain['union']->id,
        'detailed_address' => 'Warehouse 4',
        ...$overrides,
    ];
}

it('lists, creates, edits, sets default and archives a Supplier address end-to-end', function () {
    $supplier = Supplier::factory()->create();
    supplierTestSignIn($supplier);
    $chain = addressTestLocationChain();

    $this->get(route('supplier.addresses.index'))->assertOk();

    $this->post(route('supplier.addresses.store'), supplierAddressTestPayload($chain, ['is_default' => true]))
        ->assertRedirect();

    $address = SharedAddress::query()->firstOrFail();
    expect($address->is_default)->toBeTrue()
        ->and($address->owner_id)->toBe($supplier->id)
        ->and($address->owner_type)->toBe(AddressOwnerType::Supplier->value);

    $this->put(route('supplier.addresses.update', $address->public_id), supplierAddressTestPayload($chain, [
        'contact_name' => 'New Warehouse Manager',
    ]))->assertRedirect();

    expect($address->refresh()->contact_name)->toBe('New Warehouse Manager');

    $second = addressTestCreate(AddressOwnerType::Supplier, $supplier->id, ['type' => SupplierAddressType::Pickup->value]);

    $this->post(route('supplier.addresses.default', $second->public_id))->assertRedirect();

    expect($second->refresh()->is_default)->toBeTrue()
        ->and($address->refresh()->is_default)->toBeFalse();

    $this->post(route('supplier.addresses.archive', $second->public_id))->assertRedirect();

    expect($second->refresh()->status)->toBe(AddressStatus::Archived);
});

it('refuses to reach another Supplier\'s address (D25)', function () {
    $supplier = Supplier::factory()->create();
    $other = Supplier::factory()->create();
    $address = addressTestCreate(AddressOwnerType::Supplier, $other->id);
    $chain = addressTestLocationChain();

    supplierTestSignIn($supplier);

    $this->put(route('supplier.addresses.update', $address->public_id), supplierAddressTestPayload($chain))
        ->assertNotFound();

    $this->post(route('supplier.addresses.archive', $address->public_id))
        ->assertNotFound();
});

it('never lets a Client/Partner account reach a Supplier\'s address, or the reverse', function () {
    $account = testBusinessAccount();
    $supplierAddress = addressTestCreate(AddressOwnerType::Supplier, Supplier::factory()->create()->id);
    $clientAddress = addressTestCreate(AddressOwnerType::BusinessAccount, $account->id);

    $this->actingAs($account->owner)
        ->get(route('addresses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('addresses', fn ($addresses) => collect($addresses)
            ->pluck('id')
            ->doesntContain($supplierAddress->public_id)));

    $supplier = Supplier::factory()->create();
    supplierTestSignIn($supplier);

    $this->get(route('supplier.addresses.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('addresses', fn ($addresses) => collect($addresses)
            ->pluck('id')
            ->doesntContain($clientAddress->public_id)));
});
