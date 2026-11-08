<?php

use App\Domain\Supplier\Actions\OpenSupplierWallet;
use App\Domain\Supplier\Data\SupplierPostingContext;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\SupplierWalletService;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;

/*
 * A Supplier's own wallet and statement over the mobile API (D25, P13-23):
 * flat Taka as exact decimal strings, read-only, self-scoped.
 */

function mobileSupplierWalletCredit(Supplier $supplier, string $amount, string $key): void
{
    app(SupplierWalletService::class)->credit(
        app(OpenSupplierWallet::class)->handle($supplier, Currency::BDT),
        Money::fromDecimal($amount, Currency::BDT),
        new SupplierPostingContext(source: 'manual', description: 'Test funding '.$key, idempotencyKey: 'mobile-supplier-wallet-test:'.$key),
    );
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->supplier = Supplier::factory()->create();
    $this->other = Supplier::factory()->create();
});

test('a supplier with no movement yet has no wallet and an empty statement', function () {
    Sanctum::actingAs($this->supplier);

    $this->getJson(route('mobile.v1.supplier.wallet.show'))
        ->assertOk()
        ->assertJsonPath('wallet', null)
        ->assertJsonPath('payable_totals.pending.amount', '0.00')
        ->assertJsonPath('recent_entries', []);

    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('the wallet carries server-computed figures as exact decimal strings', function () {
    mobileSupplierWalletCredit($this->supplier, '1250.50', 'a');

    Sanctum::actingAs($this->supplier);

    $this->getJson(route('mobile.v1.supplier.wallet.show'))
        ->assertOk()
        ->assertJsonPath('wallet.total.amount', '1250.50')
        ->assertJsonPath('wallet.withdrawable.amount', '1250.50')
        ->assertJsonPath('wallet.reserved.amount', '0.00')
        ->assertJsonPath('wallet.has_outstanding_recovery', false)
        ->assertJsonCount(1, 'recent_entries')
        ->assertJsonPath('recent_entries.0.amount.amount', '1250.50')
        ->assertJsonPath('recent_entries.0.is_credit', true);
});

test('the statement is paginated, filterable and never carries an internal note', function () {
    mobileSupplierWalletCredit($this->supplier, '100.00', 'a');
    mobileSupplierWalletCredit($this->supplier, '200.00', 'b');

    Sanctum::actingAs($this->supplier);

    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.balance_after.amount', '300.00')
        ->assertJsonMissingPath('data.0.internal_note')
        ->assertJsonMissingPath('data.0.idempotency_key')
        ->assertJsonStructure(['links', 'meta', 'types' => [['value', 'label']]]);

    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index', ['type' => 'withdrawal_paid_debit']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index', ['type' => 'bogus']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');

    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a supplier never sees another supplier\'s wallet or ledger', function () {
    mobileSupplierWalletCredit($this->other, '999.00', 'theirs');

    Sanctum::actingAs($this->supplier);

    $this->getJson(route('mobile.v1.supplier.wallet.show'))->assertOk()->assertJsonPath('wallet', null);
    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index'))->assertOk()->assertJsonCount(0, 'data');
});

test('the wallet needs a token and an operational supplier', function () {
    $this->getJson(route('mobile.v1.supplier.wallet.show'))->assertUnauthorized();
    $this->getJson(route('mobile.v1.supplier.wallet.transactions.index'))->assertUnauthorized();

    Sanctum::actingAs(Supplier::factory()->suspended()->create());

    $this->getJson(route('mobile.v1.supplier.wallet.show'))
        ->assertForbidden()
        ->assertJsonPath('code', 'supplier_not_operational');
});
