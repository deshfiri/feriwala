<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Supplier\Actions\SavePayoutMethod;
use App\Domain\Supplier\Actions\SettleSupplierPayable;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierPayable;
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

/*
 * Staff-side Supplier finance screens (D25, P13-23, P13-24): settlement,
 * read-only wallet visibility, and the withdrawal decision queue. Every
 * money-moving route sits behind `RequirePassword`; every route is gated by
 * the existing `Module::SupplierPayable`/`Module::Withdrawal` permissions,
 * never a Supplier-specific one invented for this batch.
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
    $this->offer = supplierTestOffer($this->supplier, $this->product, supplierRate: '1000.00', platformRate: '1300.00', preferred: true);
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

    // SupplierManager first, not layered on afterwards: `requiresTwoFactor()`
    // is read from a role's own sensitive permissions, and the factory only
    // enrols two-factor for the role it is given at creation. Assigning
    // SupplierManager after the fact would leave a manager the live
    // `two-factor` middleware correctly bounces, since their held
    // permissions would demand it despite never having been enrolled.
    $this->manager = testPlatformStaff(PlatformRole::SupplierManager);
    $this->manager->assignRole(PlatformRole::OrderManager->value);
    $this->manager->assignRole(PlatformRole::InventoryManager->value);
});

describe('payable settlement', function () {
    it('settles an Eligible payable once confirmed with a password', function () {
        $payable = supplierWalletTestEligiblePayable(2);

        $this->actingAs($this->manager)
            ->post(route('admin.supplier-payables.settle', $payable->public_id))
            ->assertRedirect(route('password.confirm'));

        expect($payable->fresh()->status)->toBe(PayableStatus::Eligible);

        $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.supplier-payables.settle', $payable->public_id))
            ->assertRedirect();

        expect($payable->fresh()->status)->toBe(PayableStatus::Settled);

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->total->toDecimal())->toBe('2000.00');
    });

    it('refuses settlement to a role holding only supplier_payable.view', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        $viewOnly = testPlatformStaff(PlatformRole::OrderManager);
        $viewOnly->assignRole(PlatformRole::SupplierManager->value);

        // SupplierManager holds .approve too, so this proves the boundary
        // with a role that genuinely has neither.
        $noAccess = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($noAccess)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.supplier-payables.settle', $payable->public_id))
            ->assertForbidden();

        expect($payable->fresh()->status)->toBe(PayableStatus::Eligible);
    });

    it('settles independent payables in bulk, one refusal never affecting the others', function () {
        $good = supplierWalletTestEligiblePayable(1);

        $secondSupplier = Supplier::factory()->create();
        $secondOffer = supplierTestOffer($secondSupplier, supplierRate: '500.00', platformRate: '700.00', preferred: true);
        supplierTestOfferPriceVersion($secondOffer);
        $this->selection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $secondOffer->product_id,
            'status' => WebsiteProductStatus::Published,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price_minor' => 70000,
            'published_at' => now(),
        ]);
        $this->offer = $secondOffer;
        $goodToo = supplierWalletTestEligiblePayable(1);

        $badOrder = supplierOrderPlace($this->selection, 1);
        $bad = SupplierPayable::query()->where('order_id', $badOrder->id)->sole();

        $response = $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.supplier-payables.bulk-settle'), [
                'payables' => [$good->public_id, $bad->public_id, $goodToo->public_id],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('bulk_result', function (array $result) {
            return $result['succeeded'] === 2 && count($result['refused']) === 1;
        });

        expect($good->fresh()->status)->toBe(PayableStatus::Settled)
            ->and($goodToo->fresh()->status)->toBe(PayableStatus::Settled)
            ->and($bad->fresh()->status)->toBe(PayableStatus::Pending);
    });
});

describe('wallet visibility', function () {
    it('shows the wallet list and detail to supplier_payable.view', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);
        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();

        $this->actingAs($this->manager)->get(route('admin.supplier-wallets.index'))->assertOk();
        $this->actingAs($this->manager)->get(route('admin.supplier-wallets.show', $wallet->public_id))->assertOk();
    });

    it('refuses wallet visibility to a role without supplier_payable.view', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);
        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();

        $noAccess = testPlatformStaff(PlatformRole::PackageManager);

        $this->actingAs($noAccess)->get(route('admin.supplier-wallets.index'))->assertForbidden();
        $this->actingAs($noAccess)->get(route('admin.supplier-wallets.show', $wallet->public_id))->assertForbidden();
    });
});

describe('withdrawal decisions', function () {
    beforeEach(function () {
        $payable = supplierWalletTestEligiblePayable(3);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);
        $method = app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Bkash, 'bKash', ['account_name' => 'Test', 'account_number' => '01711112222'],
        );
        $this->withdrawal = app(RequestSupplierWithdrawal::class)->handle(
            $this->supplier, $method, Money::fromDecimal('1000.00', Currency::BDT), 'admin-test:withdrawal',
        );
    });

    it('lets SupplierManager approve, but refuses release-payment to it', function () {
        $this->actingAs($this->manager)
            ->post(route('admin.supplier-withdrawals.approve', $this->withdrawal->public_id))
            ->assertRedirect();

        expect($this->withdrawal->fresh()->status)->toBe(SupplierWithdrawalStatus::Approved);

        $this->actingAs($this->manager)
            ->post(route('admin.supplier-withdrawals.process', $this->withdrawal->public_id))
            ->assertRedirect();

        expect($this->withdrawal->fresh()->status)->toBe(SupplierWithdrawalStatus::Processing);

        // SupplierManager holds Approve/Reject/Edit/View, never ReleasePayment.
        $this->actingAs($this->manager)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.supplier-withdrawals.paid', $this->withdrawal->public_id), [
                'external_reference' => 'BKASH-TXN-1',
            ])
            ->assertForbidden();

        expect($this->withdrawal->fresh()->status)->toBe(SupplierWithdrawalStatus::Processing);
    });

    it('lets a WithdrawalApprover release a payment, only once confirmed with a password', function () {
        $this->actingAs($this->manager)->post(route('admin.supplier-withdrawals.approve', $this->withdrawal->public_id));
        $this->actingAs($this->manager)->post(route('admin.supplier-withdrawals.process', $this->withdrawal->public_id));

        $approver = testPlatformStaff(PlatformRole::WithdrawalApprover);

        $this->actingAs($approver)
            ->post(route('admin.supplier-withdrawals.paid', $this->withdrawal->public_id), [
                'external_reference' => 'BKASH-TXN-1',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->actingAs($approver)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.supplier-withdrawals.paid', $this->withdrawal->public_id), [
                'external_reference' => 'BKASH-TXN-1',
            ])
            ->assertRedirect();

        $fresh = $this->withdrawal->fresh();
        expect($fresh->status)->toBe(SupplierWithdrawalStatus::Paid)
            ->and($fresh->external_reference)->toBe('BKASH-TXN-1');

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->reserved->toDecimal())->toBe('0.00');
    });

    it('releases the reservation exactly once on rejection, with a reason', function () {
        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->reserved->toDecimal())->toBe('1000.00');

        $this->actingAs($this->manager)
            ->post(route('admin.supplier-withdrawals.reject', $this->withdrawal->public_id), [
                'reason' => 'Payout details could not be verified.',
            ])
            ->assertRedirect();

        expect($this->withdrawal->fresh()->status)->toBe(SupplierWithdrawalStatus::Rejected)
            ->and($wallet->fresh()->reserved->toDecimal())->toBe('0.00');
    });
});

describe('guard and self-scope isolation', function () {
    it('never sends a Client/Partner business owner Supplier finance data', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);
        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();

        $this->actingAs($this->account->owner)->get(route('admin.supplier-wallets.index'))->assertForbidden();
        $this->actingAs($this->account->owner)->get(route('admin.supplier-wallets.show', $wallet->public_id))->assertForbidden();
        $this->actingAs($this->account->owner)->get(route('admin.supplier-withdrawals.index'))->assertForbidden();
    });

    it('never lets a Supplier session reach any staff Supplier finance route', function () {
        supplierTestSignIn($this->supplier);

        $this->get(route('admin.supplier-wallets.index'))->assertRedirect(route('login'));
        $this->get(route('admin.supplier-withdrawals.index'))->assertRedirect(route('login'));
        $this->post(route('admin.supplier-payables.bulk-settle'), ['payables' => []])
            ->assertRedirect(route('login'));
    });
});
