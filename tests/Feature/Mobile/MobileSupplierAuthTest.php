<?php

use App\Domain\Supplier\Models\Supplier;
use App\Models\User;

/*
 * Suppliers are a separate account domain (D25): their own table, login and
 * tokens. A token for one must open nothing of the other's.
 */

function mobileSupplierLogin(Supplier $supplier, array $overrides = []): Illuminate\Testing\TestResponse
{
    return test()->postJson(route('mobile.v1.supplier.auth.login'), [
        'email' => $supplier->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
        ...$overrides,
    ]);
}

test('a supplier signs in and the token identifies them', function () {
    $supplier = Supplier::factory()->create();

    $token = mobileSupplierLogin($supplier)
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('supplier.id', $supplier->public_id)
        ->assertJsonPath('supplier.is_operational', true)
        ->assertJsonMissingPath('supplier.review_note')
        ->json('token');

    $this->withToken($token)
        ->getJson(route('mobile.v1.supplier.auth.me'))
        ->assertOk()
        ->assertJsonPath('data.email', $supplier->email);
});

test('a wrong password is refused', function () {
    $supplier = Supplier::factory()->create();

    mobileSupplierLogin($supplier, ['password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($supplier->tokens()->count())->toBe(0);
});

test('a client login cannot sign in as a supplier', function () {
    $user = User::factory()->create();

    $this->postJson(route('mobile.v1.supplier.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertUnprocessable();
});

test('a suspended supplier may still sign in to read its own status', function () {
    $supplier = Supplier::factory()->suspended()->create();

    mobileSupplierLogin($supplier)
        ->assertOk()
        ->assertJsonPath('supplier.is_operational', false);
});

test('a closed supplier loses its token on the next request', function () {
    $supplier = Supplier::factory()->create();
    $token = mobileSupplierLogin($supplier)->json('token');

    $supplier->forceFill(['status' => App\Domain\Supplier\Enums\SupplierStatus::Closed])->save();

    $this->withToken($token)
        ->getJson(route('mobile.v1.supplier.auth.me'))
        ->assertForbidden()
        ->assertJsonPath('code', 'account_unavailable');

    expect($supplier->tokens()->count())->toBe(0);
});

test('a supplier token is refused on client routes and a client token on supplier routes', function () {
    $supplierToken = mobileSupplierLogin(Supplier::factory()->create())->json('token');
    $user = User::factory()->create();
    $userToken = test()->postJson(route('mobile.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->json('token');

    $this->withToken($supplierToken)
        ->getJson(route('mobile.v1.auth.me'))
        ->assertForbidden()
        ->assertJsonPath('code', 'wrong_audience');

    $this->app['auth']->forgetGuards();

    $this->withToken($userToken)
        ->getJson(route('mobile.v1.supplier.auth.me'))
        ->assertForbidden()
        ->assertJsonPath('code', 'wrong_audience');
});

test('signing out revokes the supplier token', function () {
    $supplier = Supplier::factory()->create();
    $token = mobileSupplierLogin($supplier)->json('token');

    $this->withToken($token)->postJson(route('mobile.v1.supplier.auth.logout'))->assertNoContent();

    expect($supplier->tokens()->count())->toBe(0);
});

test('supplier routes need a token', function () {
    $this->getJson(route('mobile.v1.supplier.auth.me'))->assertUnauthorized();
});
