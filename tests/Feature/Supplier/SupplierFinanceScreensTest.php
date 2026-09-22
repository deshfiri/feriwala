<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Supplier\Actions\SavePayoutMethod;
use App\Domain\Supplier\Actions\SettleSupplierPayable;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayoutMethod;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Supplier-facing wallet, payout-method and withdrawal screens (D25, P13-23,
 * P13-24). Self-scoped throughout — every route resolves through the
 * authenticated Supplier's own supplier_id, never a route-model-bound record
 * that could belong to somebody else.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    $settings->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->validation = new ArrayObject(['currency_amount' => '0.00', 'currency_type' => 'BDT']);

    Http::fake(fn (ClientRequest $request) => Http::response(
        str_contains($request->url(), 'gwprocess')
            ? ['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']
            : ['status' => 'VALID', ...$this->validation->getArrayCopy()],
    ));

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    websiteTestFee(FeeType::WebsiteDelivery, 6000);
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');
    storefrontCredential($this->website, [
        CredentialScope::OrdersWrite, CredentialScope::OrdersRead, CredentialScope::CustomersWrite,
    ]);

    $this->supplier = Supplier::factory()->create();
    $this->product = websiteTestProduct();
    $this->offer = supplierTestOffer($this->supplier, $this->product, supplierRate: 100000, platformRate: 130000, preferred: true);
    supplierTestOfferPriceVersion($this->offer);

    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 130000,
        'published_at' => now(),
    ]);

    $this->manager = testPlatformStaff(PlatformRole::OrderManager);
    $this->manager->assignRole(PlatformRole::InventoryManager->value);
    $this->manager->assignRole(PlatformRole::SupplierManager->value);
});

describe('wallet dashboard', function () {
    it('shows an empty state before any payable has settled', function () {
        supplierTestSignIn($this->supplier);

        $this->get(route('supplier.wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier/wallet/index')
                ->where('wallet', null),
            );
    });

    it('renders server-computed balances once a payable settles', function () {
        $payable = supplierWalletTestEligiblePayable(2);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        supplierTestSignIn($this->supplier);

        $this->get(route('supplier.wallet.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier/wallet/index')
                ->where('wallet.total.minor_units', 200000)
                ->where('wallet.available.minor_units', 200000)
                ->where('wallet.reserved.minor_units', 0)
                ->where('wallet.recovery.minor_units', 0)
                ->where('payable_totals.settled.minor_units', 200000),
            );
    });

    it('paginates and filters the transaction history', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        supplierTestSignIn($this->supplier);

        $this->get(route('supplier.wallet.transactions'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier/wallet/transactions')
                ->has('entries.data', 1)
                ->where('entries.data.0.type', 'settlement_credit'),
            );

        $this->get(route('supplier.wallet.transactions', ['type' => 'withdrawal_paid_debit']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
    });
});

describe('payout methods', function () {
    it('refuses to create a payout method with the wrong current password', function () {
        supplierTestSignIn($this->supplier);

        $this->post(route('supplier.payout-methods.store'), [
            'current_password' => 'not-the-password',
            'type' => SupplierPayoutMethodType::Bkash->value,
            'label' => 'Primary bKash',
            'details' => ['account_name' => 'Test', 'account_number' => '01711112222'],
        ])->assertSessionHasErrors('current_password');

        expect(SupplierPayoutMethod::query()->count())->toBe(0);
    });

    it('creates a payout method and never exposes the unmasked number to the browser', function () {
        supplierTestSignIn($this->supplier);

        $response = $this->post(route('supplier.payout-methods.store'), [
            'current_password' => 'password',
            'type' => SupplierPayoutMethodType::Bkash->value,
            'label' => 'Primary bKash',
            'details' => ['account_name' => 'Test Supplier', 'account_number' => '01711112222'],
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect();

        $index = $this->get(route('supplier.payout-methods.index'));
        $index->assertInertia(fn (Assert $page) => $page
            ->component('supplier/payout-methods/index')
            ->where('methods.0.masked_number', '••••2222'),
        );

        expect($index->getContent())->not->toContain('01711112222');
    });

    it('archives a payout method only with the current password', function () {
        $method = app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Nagad, 'Nagad', ['account_name' => 'Test', 'account_number' => '01899990000'],
        );

        supplierTestSignIn($this->supplier);

        $this->post(route('supplier.payout-methods.archive', $method->public_id), [
            'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');

        $this->post(route('supplier.payout-methods.archive', $method->public_id), [
            'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        expect($method->fresh()->status->value)->toBe('archived');
    });
});

describe('withdrawals', function () {
    beforeEach(function () {
        $payable = supplierWalletTestEligiblePayable(3);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);
        $this->method = app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Bkash, 'bKash', ['account_name' => 'Test', 'account_number' => '01711112222'],
        );
    });

    it('shows the create screen with the server-computed available balance', function () {
        supplierTestSignIn($this->supplier);

        $this->get(route('supplier.withdrawals.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('supplier/withdrawals/create')
                ->where('available_balance.minor_units', 300000)
                ->has('idempotency_key'),
            );
    });

    it('requests a withdrawal, reserving the wallet, and the server refuses an amount above what is available', function () {
        supplierTestSignIn($this->supplier);

        $this->post(route('supplier.withdrawals.store'), [
            'payout_method_id' => $this->method->public_id,
            'amount_minor' => 500000,
            'idempotency_key' => 'test-key-1',
        ])->assertSessionHasErrors('amount_minor');

        $this->post(route('supplier.withdrawals.store'), [
            'payout_method_id' => $this->method->public_id,
            'amount_minor' => 100000,
            'idempotency_key' => 'test-key-2',
        ])->assertRedirect();

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->reserved_minor->minorUnits)->toBe(100000);
    });

    it('retries the same submission once, by idempotency key, rather than reserving twice', function () {
        supplierTestSignIn($this->supplier);

        $payload = [
            'payout_method_id' => $this->method->public_id,
            'amount_minor' => 100000,
            'idempotency_key' => 'test-key-retry',
        ];

        $this->post(route('supplier.withdrawals.store'), $payload)->assertRedirect();
        $this->post(route('supplier.withdrawals.store'), $payload)->assertRedirect();

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->reserved_minor->minorUnits)->toBe(100000);
    });
});

describe('self-scope and guard isolation', function () {
    it('never lets one Supplier reach another\'s wallet, payout method or withdrawal', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);
        $method = app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Bkash, 'bKash', ['account_name' => 'Test', 'account_number' => '01711112222'],
        );
        $withdrawal = app(RequestSupplierWithdrawal::class)->handle(
            $this->supplier, $method, Money::of(50000, Currency::BDT), 'scope-test',
        );

        $stranger = Supplier::factory()->create();
        supplierTestSignIn($stranger);

        $this->get(route('supplier.withdrawals.show', $withdrawal->public_id))->assertNotFound();
        $this->post(route('supplier.payout-methods.archive', $method->public_id), [
            'current_password' => 'password',
        ])->assertNotFound();

        supplierTestSignIn($this->supplier);
        $this->get(route('supplier.withdrawals.show', $withdrawal->public_id))->assertOk();
    });

    it('never sends a Supplier request to a staff route, and never a staff request to a Supplier route', function () {
        // Unauthenticated at either guard: the Supplier route redirects to the
        // Supplier login, never the staff one.
        $this->get(route('supplier.wallet.show'))
            ->assertRedirect(route('supplier.login'));

        // A signed-in staff member (web guard) has no Supplier session and is
        // redirected the same way when reaching a Supplier-guarded route.
        $this->actingAs($this->manager)
            ->get(route('supplier.wallet.show'))
            ->assertRedirect(route('supplier.login'));
    });
});
