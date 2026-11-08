<?php

use App\Domain\Payout\Actions\SavePayoutMethod;
use App\Domain\Payout\Enums\PayoutMethodType;
use App\Domain\Payout\Enums\PayoutOwnerType;
use App\Domain\Payout\Models\PayoutMethod;
use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Enums\SupplierLedgerEntryType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Supplier\Models\SupplierWithdrawal;
use App\Domain\Supplier\SupplierWalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * A Supplier's withdrawals over the mobile API (D25, P13-24). The request
 * reserves the amount on the wallet through RequestSupplierWithdrawal, in one
 * locked transaction, once per Idempotency-Key.
 */

function mobileSupplierWithdrawalFund(Supplier $supplier, string $amount): SupplierWallet
{
    $wallet = app(OpenSupplierWallet::class)->handle($supplier, Currency::BDT);

    app(SupplierWalletService::class)->credit(
        $wallet,
        Money::fromDecimal($amount, Currency::BDT),
        new SupplierPostingContext(
            source: 'manual',
            description: 'Test funding',
            idempotencyKey: 'mobile-supplier-withdrawal-test:fund:'.$supplier->id,
        ),
    );

    return $wallet->refresh();
}

function mobileSupplierWithdrawalMethod(Supplier $supplier): PayoutMethod
{
    return app(SavePayoutMethod::class)->handle(
        ownerType: PayoutOwnerType::Supplier,
        ownerId: $supplier->id,
        type: PayoutMethodType::Bkash,
        label: 'Primary bKash',
        details: ['account_holder_name' => 'Test Supplier', 'account_number' => '01711112222'],
    );
}

/**
 * @return array<string, string>
 */
function mobileSupplierWithdrawalKey(string $key = 'key-1'): array
{
    return ['Idempotency-Key' => $key];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->supplier = Supplier::factory()->create();
    $this->wallet = mobileSupplierWithdrawalFund($this->supplier, '1000.00');
    $this->method = mobileSupplierWithdrawalMethod($this->supplier);
    $this->other = Supplier::factory()->create();
});

test('the options carry the server-computed balance, limits and active methods', function () {
    Sanctum::actingAs($this->supplier);

    $this->getJson(route('mobile.v1.supplier.withdrawals.options'))
        ->assertOk()
        ->assertJsonPath('available_balance.amount', '1000.00')
        ->assertJsonPath('minimum.amount', '500.00')
        ->assertJsonPath('maximum', null)
        ->assertJsonPath('currency', 'BDT')
        ->assertJsonPath('methods.0.id', $this->method->public_id)
        ->assertJsonMissingPath('methods.0.account_number');
});

test('a withdrawal reserves the amount once and shows in the list and detail', function () {
    Sanctum::actingAs($this->supplier);

    $id = $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
        'amount' => '600.00',
    ], mobileSupplierWithdrawalKey())
        ->assertCreated()
        ->assertJsonPath('data.amount.amount', '600.00')
        ->assertJsonPath('data.status', 'requested')
        ->json('data.id');

    $wallet = $this->wallet->refresh();

    expect($wallet->reserved->toDecimal())->toBe('600.00')
        ->and($wallet->availableForWithdrawal()->toDecimal())->toBe('400.00')
        ->and($wallet->total->toDecimal())->toBe('1000.00')
        ->and(SupplierLedgerEntry::query()->where('type', SupplierLedgerEntryType::WithdrawalReserved)->count())->toBe(1);

    $this->getJson(route('mobile.v1.supplier.withdrawals.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonStructure(['statuses' => [['value', 'label']]]);

    $this->getJson(route('mobile.v1.supplier.withdrawals.show', $id))
        ->assertOk()
        ->assertJsonPath('data.payout.label', 'Primary bKash')
        ->assertJsonCount(1, 'data.history');
});

test('retrying the same Idempotency-Key returns the original and reserves nothing more', function () {
    Sanctum::actingAs($this->supplier);

    $payload = ['payout_method_id' => $this->method->public_id, 'amount' => '600.00'];

    $first = $this->postJson(route('mobile.v1.supplier.withdrawals.store'), $payload, mobileSupplierWithdrawalKey('retry-1'))
        ->assertCreated()
        ->json('data.id');

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), $payload, mobileSupplierWithdrawalKey('retry-1'))
        ->assertOk()
        ->assertJsonPath('data.id', $first);

    expect(SupplierWithdrawal::query()->count())->toBe(1)
        ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('600.00')
        ->and(SupplierLedgerEntry::query()->where('type', SupplierLedgerEntryType::WithdrawalReserved)->count())->toBe(1);
});

test('an amount above the withdrawable balance is refused and reserves nothing', function () {
    Sanctum::actingAs($this->supplier);

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
        'amount' => '1000.01',
    ], mobileSupplierWithdrawalKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect(SupplierWithdrawal::query()->count())->toBe(0)
        ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
});

test('an amount below the minimum is refused', function () {
    Sanctum::actingAs($this->supplier);

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
        'amount' => '100.00',
    ], mobileSupplierWithdrawalKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect(SupplierWithdrawal::query()->count())->toBe(0);
});

test('a missing Idempotency-Key, a missing amount and excess precision are refused', function () {
    Sanctum::actingAs($this->supplier);

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
        'amount' => '600.00',
    ])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
    ], mobileSupplierWithdrawalKey())->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
        'amount' => '600.005',
    ], mobileSupplierWithdrawalKey())->assertUnprocessable()->assertJsonValidationErrors('amount');

    expect(SupplierWithdrawal::query()->count())->toBe(0);
});

test('another supplier\'s payout method is a 404 and reserves nothing', function () {
    $theirs = mobileSupplierWithdrawalMethod($this->other);

    Sanctum::actingAs($this->supplier);

    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $theirs->public_id,
        'amount' => '600.00',
    ], mobileSupplierWithdrawalKey())->assertNotFound();

    expect(SupplierWithdrawal::query()->count())->toBe(0)
        ->and($this->wallet->refresh()->reserved->toDecimal())->toBe('0.00');
});

test('another supplier cannot open a withdrawal or see it listed', function () {
    Sanctum::actingAs($this->supplier);

    $id = $this->postJson(route('mobile.v1.supplier.withdrawals.store'), [
        'payout_method_id' => $this->method->public_id,
        'amount' => '600.00',
    ], mobileSupplierWithdrawalKey())->assertCreated()->json('data.id');

    Sanctum::actingAs($this->other);

    $this->getJson(route('mobile.v1.supplier.withdrawals.show', $id))->assertNotFound();
    $this->getJson(route('mobile.v1.supplier.withdrawals.index'))->assertOk()->assertJsonCount(0, 'data');
});

test('withdrawals need a token and an operational supplier', function () {
    $payload = ['payout_method_id' => $this->method->public_id, 'amount' => '600.00'];

    $this->getJson(route('mobile.v1.supplier.withdrawals.index'))->assertUnauthorized();
    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), $payload, mobileSupplierWithdrawalKey())->assertUnauthorized();

    Sanctum::actingAs(Supplier::factory()->suspended()->create());

    $this->getJson(route('mobile.v1.supplier.withdrawals.options'))
        ->assertForbidden()
        ->assertJsonPath('code', 'supplier_not_operational');
    $this->postJson(route('mobile.v1.supplier.withdrawals.store'), $payload, mobileSupplierWithdrawalKey())->assertForbidden();

    expect(SupplierWithdrawal::query()->count())->toBe(0);
});
