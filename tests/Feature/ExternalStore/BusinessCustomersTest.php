<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\ExternalStore\Models\ExternalCustomer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The people who bought, store by store (D29) — never merged across stores on
 * a matching number, and never another business's.
 */

beforeEach(function () {
    $this->account = externalStoreAccount(limit: 2);
    $this->owner = $this->account->owner;
    $this->first = externalStoreConnected($this->account, 'one.example.com');
    $this->second = externalStoreConnected($this->account, 'two.example.com');
});

it('keeps the same phone number in two stores as two customers', function () {
    $sameBuyer = ['customer_id' => null, 'customer' => ['name' => 'Khalid Hasan', 'phone' => '01700000000']];

    externalStoreRecordOrder($this->first, $sameBuyer);
    externalStoreRecordOrder($this->second, $sameBuyer);

    expect(ExternalCustomer::query()->count())->toBe(2);

    $this->actingAs($this->owner)
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/index')
            ->has('customers.data', 2));
});

it('knows a returning customer within one store by their store id', function () {
    externalStoreRecordOrder($this->first);
    externalStoreRecordOrder($this->first, ['id' => 2, 'order_number' => 'DF-2', 'placed_at' => '2026-10-05T10:00:00+06:00']);

    $customer = ExternalCustomer::query()->sole();

    expect($customer->orders()->count())->toBe(2)
        ->and($customer->last_order_at?->toIso8601String())->toBe('2026-10-05T10:00:00+06:00');
});

it('shows a customer\'s orders and how they went', function () {
    externalStoreRecordOrder($this->first, ['status' => 'delivered']);
    externalStoreRecordOrder($this->first, ['id' => 2, 'order_number' => 'DF-2', 'status' => 'cancelled']);
    externalStoreRecordOrder($this->first, ['id' => 3, 'order_number' => 'DF-3', 'status' => 'returned']);

    $customer = ExternalCustomer::query()->sole();

    $this->actingAs($this->owner)
        ->get(route('customers.show', ['external', $customer->public_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('customers/show')
            ->where('customer.store', 'Khalid Fashion')
            ->where('customer.stats.total', 3)
            ->where('customer.stats.delivered', 1)
            ->where('customer.stats.cancelled', 1)
            ->where('customer.stats.returned', 1)
            ->has('customer.orders', 3));
});

it('does not show another business\'s customer', function () {
    externalStoreRecordOrder(externalStoreConnected(externalStoreAccount(), 'other.example.com'));
    $theirs = ExternalCustomer::query()->sole();

    $this->actingAs($this->owner)->get(route('customers.show', ['external', $theirs->public_id]))->assertNotFound();
    $this->actingAs($this->owner)
        ->get(route('customers.index'))
        ->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
});

it('is closed to a member without order access', function () {
    $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

    $this->actingAs($staff)->get(route('customers.index'))->assertForbidden();
});
