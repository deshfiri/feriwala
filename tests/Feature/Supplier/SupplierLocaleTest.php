<?php

use App\Domain\Supplier\Models\Supplier;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * `auth:supplier` runs ahead of the global web middleware, which makes
 * `supplier` the default guard before `SetLocale` reads a user. These pin the
 * behaviour that broke once because of it: a Supplier's language must follow
 * the language switcher and the saved preference, in that order.
 */

test('a supplier can switch language and the choice holds on the next page', function () {
    supplierTestSignIn(Supplier::factory()->create());

    $this->put(route('locale.update'), ['locale' => 'bn'])->assertRedirect();

    $this->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale.current', 'bn'));
});

test('a supplier with a saved language sees it before any switcher choice', function () {
    supplierTestSignIn(Supplier::factory()->create(['locale' => 'bn']));

    $this->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale.current', 'bn'));
});

test('saving a language on the profile takes effect straight away', function () {
    $supplier = supplierTestSignIn(Supplier::factory()->create());

    $this->patch(route('supplier.profile.update'), [
        'contact_person_name' => $supplier->contact_person_name,
        'business_address' => $supplier->business_address,
        'locale' => 'bn',
    ])->assertSessionHasNoErrors();

    expect($supplier->refresh()->locale)->toBe('bn');

    $this->get(route('supplier.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale.current', 'bn'));
});

test('a client language is unaffected by supplier routes and vice versa', function () {
    $this->put(route('locale.update'), ['locale' => 'bn']);

    $this->get(route('supplier.login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale.current', 'bn'));
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale.current', 'bn'));
});
