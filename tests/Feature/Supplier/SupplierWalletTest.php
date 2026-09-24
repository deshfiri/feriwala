<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Actions\AdvanceSupplierWithdrawalStatus;
use App\Domain\Supplier\Actions\BulkSettleSupplierPayables;
use App\Domain\Supplier\Actions\PaySupplierWithdrawal;
use App\Domain\Supplier\Actions\RecordSupplierPayableDelivery;
use App\Domain\Supplier\Actions\RejectOrFailSupplierWithdrawal;
use App\Domain\Supplier\Actions\RequestSupplierWithdrawal;
use App\Domain\Supplier\Actions\SavePayoutMethod;
use App\Domain\Supplier\Actions\SetSupplierWithdrawalLimits;
use App\Domain\Supplier\Actions\SettleSupplierPayable;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodStatus;
use App\Domain\Supplier\Enums\SupplierPayoutMethodType;
use App\Domain\Supplier\Enums\SupplierWithdrawalStatus;
use App\Domain\Supplier\Exceptions\SupplierPayableSettlementRefused;
use App\Domain\Supplier\Exceptions\SupplierWalletOperationRefused;
use App\Domain\Supplier\Exceptions\SupplierWithdrawalRefused;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierLedgerEntry;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierPayoutMethod;
use App\Domain\Supplier\Models\SupplierWallet;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Supplier wallet settlement (P13-23), the settlement-side of reversal
 * clawback (P13-25), payout methods and withdrawals (P13-24) — one Supplier,
 * one order pipeline, exactly as SupplierOrderAllocationTest builds one.
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
        'price' => Money::fromDecimal('1300.00', Currency::BDT),
        'published_at' => now(),
    ]);

    $this->manager = testPlatformStaff(PlatformRole::OrderManager);
    $this->manager->assignRole(PlatformRole::InventoryManager->value);
    $this->manager->assignRole(PlatformRole::SupplierManager->value);
});

/**
 * `supplierOrderIpn()` (defined in SupplierOrderAllocationTest.php, loaded
 * alongside this file) hardcodes `val_id => 'val-1'` — fine for a test that
 * pays one order, but `payments.gateway_reference` is unique, so a second
 * order paid the same way in the same test collides and never reaches Paid.
 * This variant gives every call its own val_id, for the tests below that
 * settle more than one payable.
 */
function supplierWalletTestPay(Order $order): void
{
    $payment = $order->refresh()->payment()->firstOrFail();

    test()->validation['currency_amount'] = $payment->amount->toDecimal();
    test()->validation['currency_type'] = $payment->amount->currency->value;

    $valId = 'val-'.Str::random(12);
    $fields = ['tran_id' => (string) $payment->reference, 'val_id' => $valId, 'status' => 'VALID'];
    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    test()->post(route('webhooks.payment', 'sslcommerz'), $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn ($key, $value) => $key.'='.$value, array_keys($signed), $signed))),
    ]);
}

/**
 * An Eligible payable for `$quantity` units, delivered and paid.
 */
function supplierWalletTestEligiblePayable(int $quantity = 1): SupplierPayable
{
    $test = test();
    $order = supplierOrderPlace($test->selection, $quantity);
    supplierWalletTestPay($order);
    supplierOrderDeliver($order->refresh(), $test->offer);
    app(RecordSupplierPayableDelivery::class)->handle($test->manager, $order->items()->sole());

    /** @var SupplierPayable */
    return SupplierPayable::query()->where('order_id', $order->id)->sole();
}

describe('settlement', function () {
    it('settles an Eligible payable into the Supplier wallet, once', function () {
        $payable = supplierWalletTestEligiblePayable(2);

        $settled = app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        expect($settled->status)->toBe(PayableStatus::Settled)
            ->and($settled->settled_at)->not->toBeNull()
            ->and($settled->settlement_reference)->not->toBeNull();

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->total->toDecimal())->toBe('2000.00')
            ->and($wallet->availableBalance()->toDecimal())->toBe('2000.00');

        $entry = SupplierLedgerEntry::query()->where('supplier_wallet_id', $wallet->id)->sole();
        expect($entry->credit->toDecimal())->toBe('2000.00')
            ->and($entry->reference)->toBe($settled->settlement_reference)
            ->and($entry->supplier_payable_id)->toBe($payable->id);

        // Repeating the exact same settlement changes nothing further.
        $again = app(SettleSupplierPayable::class)->handle($payable->fresh(), $this->manager->id);
        expect($again->settlement_reference)->toBe($settled->settlement_reference)
            ->and(SupplierLedgerEntry::query()->where('supplier_wallet_id', $wallet->id)->count())->toBe(1);
    });

    it('refuses to settle a payable that is not Eligible', function () {
        $payable = supplierWalletTestEligiblePayable(1);
        $payable->forceFill([
            'status' => PayableStatus::OnHold,
            'held_at' => now(),
            'hold_reason' => 'Fixture hold.',
        ])->save();

        expect(fn () => app(SettleSupplierPayable::class)->handle($payable->fresh(), $this->manager->id))
            ->toThrow(SupplierPayableSettlementRefused::class);

        expect(SupplierWallet::query()->where('supplier_id', $this->supplier->id)->exists())->toBeFalse();
    });

    it('settles independent payables in bulk, one refusal never affecting the others', function () {
        $good = supplierWalletTestEligiblePayable(1);

        $secondSupplier = Supplier::factory()->create();
        $secondOffer = supplierTestOffer($secondSupplier, supplierRate: '500.00', platformRate: '700.00', preferred: true);
        supplierTestOfferPriceVersion($secondOffer);
        $secondSelection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $secondOffer->product_id,
            'status' => WebsiteProductStatus::Published,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price' => Money::fromDecimal('700.00', Currency::BDT),
            'published_at' => now(),
        ]);
        $this->selection = $secondSelection;
        $this->offer = $secondOffer;
        $goodToo = supplierWalletTestEligiblePayable(1);

        $badOrder = supplierOrderPlace($secondSelection, 1);
        // Never paid or delivered: stays Pending, cannot settle.
        $bad = SupplierPayable::query()->where('order_id', $badOrder->id)->sole();

        $results = app(BulkSettleSupplierPayables::class)->handle([$good, $bad, $goodToo], $this->manager->id);

        expect($results[0]['ok'])->toBeTrue()
            ->and($results[1]['ok'])->toBeFalse()
            ->and($results[2]['ok'])->toBeTrue()
            ->and($good->fresh()->status)->toBe(PayableStatus::Settled)
            ->and($goodToo->fresh()->status)->toBe(PayableStatus::Settled)
            ->and($bad->fresh()->status)->toBe(PayableStatus::Pending);
    });
});

describe('reversal after settlement', function () {
    it('claws back the settled amount from the wallet when a return arrives afterwards', function () {
        $payable = supplierWalletTestEligiblePayable(3);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        expect($wallet->total->toDecimal())->toBe('3000.00');

        $order = $payable->order;
        $sku = $order->items()->sole()->sku;
        $warehouse = Warehouse::create(['code' => 'WLT', 'name' => 'Wallet returns', 'is_default' => false]);

        [$return] = app(RequestOrderReturn::class)->handle($order->refresh(), new ReturnSubmission(
            reason: ReturnReason::NotAsDescribed,
            lines: [['sku' => $sku, 'quantity' => 1]],
        ), OrderStatusChangeSource::Storefront);

        app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'Confirmed by the test.');

        app(ReceiveReturnedItems::class)->handle(
            $this->manager,
            $return,
            [new ReturnedLine($return->items()->sole()->public_id, 1, ReturnDisposition::Restock)],
            $warehouse,
        );

        $wallet->refresh();
        expect($wallet->total->toDecimal())->toBe('2000.00')
            ->and($wallet->recovery->toDecimal())->toBe('0.00');
    });

    it('records recovery instead of a negative balance when a reversal exceeds what is available', function () {
        $payable = supplierWalletTestEligiblePayable(2);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        $wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();

        // A withdrawal takes almost everything available first.
        $method = app(SavePayoutMethod::class)->handle(
            $this->supplier,
            SupplierPayoutMethodType::Bkash,
            'Primary bKash',
            ['account_name' => 'Test Supplier', 'account_number' => '01711112222'],
        );
        app(RequestSupplierWithdrawal::class)->handle($this->supplier, $method, Money::fromDecimal('1500.00', Currency::BDT), 'wallet-test:withdrawal:1');

        expect($wallet->fresh()->availableBalance()->toDecimal())->toBe('500.00');

        $order = $payable->order;
        $sku = $order->items()->sole()->sku;
        $warehouse = Warehouse::create(['code' => 'REC', 'name' => 'Recovery returns', 'is_default' => false]);

        [$return] = app(RequestOrderReturn::class)->handle($order->refresh(), new ReturnSubmission(
            reason: ReturnReason::NotAsDescribed,
            lines: [['sku' => $sku, 'quantity' => 2]],
        ), OrderStatusChangeSource::Storefront);

        app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'Confirmed.');

        app(ReceiveReturnedItems::class)->handle(
            $this->manager,
            $return,
            [new ReturnedLine($return->items()->sole()->public_id, 2, ReturnDisposition::Restock)],
            $warehouse,
        );

        $wallet->refresh();

        // 2000.00 owed back, only 500.00 was available: 500.00 debited off
        // total (2000.00 -> 1500.00), 1500.00 recorded as recovery, never a
        // negative available balance.
        expect($wallet->total->toDecimal())->toBe('1500.00')
            ->and($wallet->reserved->toDecimal())->toBe('1500.00')
            ->and($wallet->recovery->toDecimal())->toBe('1500.00')
            ->and($wallet->availableBalance()->toDecimal())->toBe('0.00');
    });
});

describe('payout methods', function () {
    it('creates a payout method, storing only the masked number outside the encrypted column', function () {
        $method = app(SavePayoutMethod::class)->handle(
            $this->supplier,
            SupplierPayoutMethodType::BankAccount,
            'Primary bank',
            ['bank_name' => 'City Bank', 'account_name' => 'Test Supplier', 'account_number' => '123456789012'],
        );

        expect($method->last_four)->toBe('9012')
            ->and($method->maskedNumber())->toBe('••••9012')
            ->and($method->status)->toBe(SupplierPayoutMethodStatus::Active)
            ->and(json_encode($method))->not->toContain('123456789012');
    });

    it('archives a payout method without deleting it, and a withdrawal keeps its own snapshot', function () {
        $method = app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Nagad, 'Nagad', ['account_name' => 'Test', 'account_number' => '01899990000'],
        );

        $payable = supplierWalletTestEligiblePayable(1);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        $withdrawal = app(RequestSupplierWithdrawal::class)->handle($this->supplier, $method, Money::fromDecimal('500.00', Currency::BDT), 'wallet-test:snapshot');
        $snapshotBefore = $withdrawal->payout_snapshot;

        // Renaming the method afterwards changes nothing about the snapshot
        // the withdrawal already took, and the row is archived, not deleted.
        app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Nagad, 'Nagad (renamed)', ['account_name' => 'Test', 'account_number' => '01711110000'],
            existing: $method,
        );
        app(SavePayoutMethod::class)->archive($method->fresh());

        // toEqual, not toBe: jsonb does not preserve key order, so a round
        // trip through the database is only guaranteed to match by value.
        expect($method->fresh()->status)->toBe(SupplierPayoutMethodStatus::Archived)
            ->and($method->fresh()->label)->toBe('Nagad (renamed)')
            ->and(SupplierPayoutMethod::query()->count())->toBe(1)
            ->and($withdrawal->fresh()->payout_snapshot)->toEqual($snapshotBefore);
    });
});

describe('withdrawals', function () {
    beforeEach(function () {
        $payable = supplierWalletTestEligiblePayable(3);
        app(SettleSupplierPayable::class)->handle($payable, $this->manager->id);

        $this->wallet = SupplierWallet::query()->where('supplier_id', $this->supplier->id)->sole();
        $this->method = app(SavePayoutMethod::class)->handle(
            $this->supplier, SupplierPayoutMethodType::Bkash, 'bKash', ['account_name' => 'Test', 'account_number' => '01711112222'],
        );
    });

    it('reserves the requested amount and refuses below the configured minimum', function () {
        app(SetSupplierWithdrawalLimits::class)->setDefault(User::factory()->create(), '100.00', null);

        expect(fn () => app(RequestSupplierWithdrawal::class)->handle($this->supplier, $this->method, Money::fromDecimal('50.00', Currency::BDT), 'wallet-test:below-min'))
            ->toThrow(SupplierWithdrawalRefused::class);

        $withdrawal = app(RequestSupplierWithdrawal::class)->handle($this->supplier, $this->method, Money::fromDecimal('1000.00', Currency::BDT), 'wallet-test:withdrawal-a');

        expect($withdrawal->status)->toBe(SupplierWithdrawalStatus::Requested)
            ->and($this->wallet->fresh()->reserved->toDecimal())->toBe('1000.00')
            ->and($withdrawal->payout_snapshot['masked_number'])->toBe('••••2222');
    });

    it('refuses to reserve more than is available', function () {
        expect(fn () => app(RequestSupplierWithdrawal::class)->handle($this->supplier, $this->method, Money::fromDecimal('4000.00', Currency::BDT), 'wallet-test:too-much'))
            ->toThrow(SupplierWalletOperationRefused::class);
    });

    it('runs the full approve -> processing -> paid lifecycle, consuming the reservation once', function () {
        $staff = User::factory()->create();
        $withdrawal = app(RequestSupplierWithdrawal::class)->handle($this->supplier, $this->method, Money::fromDecimal('1000.00', Currency::BDT), 'wallet-test:paid-flow');

        $withdrawal = app(AdvanceSupplierWithdrawalStatus::class)->handle($withdrawal, SupplierWithdrawalStatus::UnderReview, $staff->id);
        $withdrawal = app(AdvanceSupplierWithdrawalStatus::class)->handle($withdrawal, SupplierWithdrawalStatus::Approved, $staff->id);
        $withdrawal = app(AdvanceSupplierWithdrawalStatus::class)->handle($withdrawal, SupplierWithdrawalStatus::Processing, $staff->id);
        $withdrawal = app(PaySupplierWithdrawal::class)->handle($withdrawal, 'BKASH-TXN-1', $staff->id);

        expect($withdrawal->status)->toBe(SupplierWithdrawalStatus::Paid)
            ->and($withdrawal->external_reference)->toBe('BKASH-TXN-1')
            ->and($this->wallet->fresh()->total->toDecimal())->toBe('2000.00')
            ->and($this->wallet->fresh()->reserved->toDecimal())->toBe('0.00');

        // Paying again is refused — the state machine has no move left.
        expect(fn () => app(PaySupplierWithdrawal::class)->handle($withdrawal->fresh(), 'BKASH-TXN-2', $staff->id))
            ->toThrow(SupplierWithdrawalRefused::class);
        expect($this->wallet->fresh()->total->toDecimal())->toBe('2000.00');
    });

    it('releases the reservation exactly once on rejection', function () {
        $staff = User::factory()->create();
        $withdrawal = app(RequestSupplierWithdrawal::class)->handle($this->supplier, $this->method, Money::fromDecimal('1000.00', Currency::BDT), 'wallet-test:rejected-flow');

        $withdrawal = app(RejectOrFailSupplierWithdrawal::class)->handle($withdrawal, SupplierWithdrawalStatus::Rejected, 'Fixture rejection.', $staff->id);

        expect($withdrawal->status)->toBe(SupplierWithdrawalStatus::Rejected)
            ->and($this->wallet->fresh()->reserved->toDecimal())->toBe('0.00')
            ->and($this->wallet->fresh()->total->toDecimal())->toBe('3000.00');

        expect(fn () => app(RejectOrFailSupplierWithdrawal::class)->handle($withdrawal->fresh(), SupplierWithdrawalStatus::Rejected, 'Again.', $staff->id))
            ->toThrow(SupplierWithdrawalRefused::class);
        expect($this->wallet->fresh()->reserved->toDecimal())->toBe('0.00');
    });
});
