<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Billing\Models\Payment;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\RecordCodCollection;
use App\Domain\Order\Actions\SynchronizeOnlineCollectionWithPayment;
use App\Domain\Order\Actions\SynchronizeOrderProceedsWithDelivery;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\CollectionChannel;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * A Non-Conditional Store Order paid online earns its profit from the verified
 * payment, not from cash somebody types (D36).
 *
 * The store sells 2 units at ৳2,600 (৳5,200); the platform keeps its suggested
 * ৳2,500 each (৳5,000), so the Partner's profit is ৳200 — credited once, as
 * withdrawable money, only when the order is delivered.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    Http::fake(fn () => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']));

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    $this->product = websiteTestProduct();
    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);

    // The account owns no units, so the order is filled from central stock.
    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'available' => 20]);

    $this->staff = testPlatformStaff(PlatformRole::OrderManager);
});

function onlineProfitPlace(string $method): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    test()->website->forceFill(['sms_verification_enabled' => false])->save();

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-OP-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => 2]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: [
            'subtotal' => $money('5200.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('0.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('5200.00'),
        ],
        paymentMethod: $method,
    ));

    return $order->refresh();
}

/**
 * The gateway confirms the payment, and the order is confirmed from it.
 */
function onlineProfitPay(Order $order): Payment
{
    $payment = $order->payment;
    $payment->transitionTo(PaymentStatus::Initiated)->save();
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'op-'.Str::random(8)])->save();

    app(ConfirmOrderPayment::class)->handle($payment->refresh());

    return $payment->refresh();
}

function onlineProfitDeliver(Order $order): void
{
    app(SynchronizeOrderProceedsWithDelivery::class)->handle($order->refresh()->load('items', 'payment'), OrderDeliveryStatus::Delivered);
}

function onlineProfitSettlement(Order $order): ?OrderProceedsSettlement
{
    return OrderProceedsSettlement::query()->where('order_id', $order->id)->first();
}

function onlineProfitWallet(): ?Wallet
{
    return Wallet::query()->where('business_account_id', test()->account->id)->first();
}

/**
 * Make the order's payment a Partner-owned one, the only way a test can: the
 * ownership is set once in the database, so the guard is lifted for this fixture.
 */
function onlineProfitOwnedByPartner(Payment $payment): void
{
    $configuration = PartnerGatewayConfiguration::create([
        'business_account_id' => test()->account->id,
        'website_id' => test()->website->id,
        'provider' => 'sslcommerz',
        'status' => 'active',
    ]);

    $version = PartnerGatewayConfigurationVersion::create([
        'partner_gateway_configuration_id' => $configuration->id,
        'business_account_id' => test()->account->id,
        'website_id' => test()->website->id,
        'version' => 1,
        'provider' => 'sslcommerz',
        'mode' => 'sandbox',
        'credentials' => ['store_id' => 'partner-store', 'store_password' => 'partner-pass'],
    ]);

    DB::statement('ALTER TABLE payments DISABLE TRIGGER payments_gateway_ownership_set_once');
    DB::table('payments')->where('id', $payment->id)->update([
        'gateway_ownership' => 'partner',
        'funds_destination' => 'partner',
        'partner_gateway_configuration_version_id' => $version->id,
    ]);
    DB::statement('ALTER TABLE payments ENABLE TRIGGER payments_gateway_ownership_set_once');
}

function onlineProfitWithoutOwnership(Payment $payment): void
{
    DB::statement('ALTER TABLE payments DISABLE TRIGGER payments_gateway_ownership_set_once');
    DB::table('payments')->where('id', $payment->id)->update([
        'gateway_ownership' => null,
        'funds_destination' => null,
        'website_id' => null,
        'partner_gateway_configuration_version_id' => null,
    ]);
    DB::statement('ALTER TABLE payments ENABLE TRIGGER payments_gateway_ownership_set_once');
}

describe('a Platform-gateway order', function () {
    it('records the verified payment as the collection fact, and credits nothing before delivery', function () {
        $order = onlineProfitPlace('online');
        onlineProfitPay($order);

        $settlement = onlineProfitSettlement($order);

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($settlement->collection_channel)->toBe(CollectionChannel::PlatformGateway)
            ->and($settlement->payment_collected_amount->toDecimal())->toBe('5200.00')
            ->and($settlement->collection_payment_id)->toBe($order->payment_id)
            // Not pretending to be cash on delivery.
            ->and($settlement->cod_collected_at)->toBeNull()
            ->and($settlement->cod_amount_collected)->toBeNull()
            ->and($settlement->recovered_amount->toDecimal())->toBe('5000.00')
            ->and($settlement->eligible_at)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('credits the profit once, as withdrawable money, when the order is delivered', function () {
        $order = onlineProfitPlace('online');
        onlineProfitPay($order);

        onlineProfitDeliver($order);

        $settlement = onlineProfitSettlement($order);
        $entry = LedgerEntry::query()->sole();

        expect($settlement->eligible_at)->not->toBeNull()
            ->and($settlement->reseller_earning->toDecimal())->toBe('200.00')
            ->and($settlement->flagged_for_review)->toBeFalse()
            ->and($entry->type)->toBe(LedgerTransactionType::SalesCredit)
            ->and($entry->balance_type)->toBe(BalanceType::Withdrawable)
            ->and(onlineProfitWallet()->total->toDecimal())->toBe('200.00');
    });

    it('credits nothing at delivery when the order was never paid online', function () {
        $order = onlineProfitPlace('online');

        onlineProfitDeliver($order);

        expect(onlineProfitSettlement($order)->eligible_at)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('never pays the profit twice however often the payment or delivery is replayed', function () {
        $order = onlineProfitPlace('online');
        $payment = onlineProfitPay($order);

        app(ConfirmOrderPayment::class)->handle($payment);
        app(SynchronizeOnlineCollectionWithPayment::class)->handle($order->refresh()->load('items'), $payment);
        onlineProfitDeliver($order);
        onlineProfitDeliver($order);
        app(SynchronizeOnlineCollectionWithPayment::class)->handle($order->refresh()->load('items'), $payment);

        expect(OrderProceedsSettlement::query()->where('order_id', $order->id)->count())->toBe(1)
            ->and(LedgerEntry::query()->count())->toBe(1)
            ->and(onlineProfitWallet()->total->toDecimal())->toBe('200.00')
            ->and(AuditLog::query()->where('action', 'order_proceeds_settlement.eligible')->count())->toBe(1);
    });

    it('repairs a collection fact that was never written, at delivery', function () {
        $order = onlineProfitPlace('online');
        onlineProfitPay($order);

        // A failed attempt left no fact behind.
        DB::statement('ALTER TABLE order_proceeds_settlements DISABLE TRIGGER order_proceeds_settlements_kept');
        DB::table('order_proceeds_settlements')->where('order_id', $order->id)->delete();
        DB::statement('ALTER TABLE order_proceeds_settlements ENABLE TRIGGER order_proceeds_settlements_kept');

        onlineProfitDeliver($order);

        expect(onlineProfitSettlement($order)->collection_channel)->toBe(CollectionChannel::PlatformGateway)
            ->and(onlineProfitSettlement($order)->reseller_earning->toDecimal())->toBe('200.00')
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('gives staff no cash on delivery to confirm for an online-collected line', function () {
        $order = onlineProfitPlace('online');
        onlineProfitPay($order);

        expect(fn () => app(RecordCodCollection::class)->handle($this->staff, $order->items()->sole(), Money::fromDecimal('5200.00', Currency::BDT)))
            ->toThrow(InvalidArgumentException::class, 'payment gateway');

        expect(onlineProfitSettlement($order)->cod_collected_at)->toBeNull();
    });

    it('records nothing when the settled payment is not exactly the order\'s total', function () {
        $order = onlineProfitPlace('online');
        $payment = onlineProfitPay($order);

        DB::statement('ALTER TABLE order_proceeds_settlements DISABLE TRIGGER order_proceeds_settlements_kept');
        DB::table('order_proceeds_settlements')->where('order_id', $order->id)->delete();
        DB::statement('ALTER TABLE order_proceeds_settlements ENABLE TRIGGER order_proceeds_settlements_kept');

        $short = $payment->replicate();
        $short->id = $payment->id;
        $short->amount = Money::fromDecimal('5000.00', Currency::BDT);

        app(SynchronizeOnlineCollectionWithPayment::class)->handle($order->refresh()->load('items'), $short);

        expect(onlineProfitSettlement($order))->toBeNull();
    });
});

describe('cash on delivery is unchanged', function () {
    it('still settles from the staff-confirmed amount, now marked as cash', function () {
        $order = onlineProfitPlace('cod');

        onlineProfitDeliver($order);
        app(RecordCodCollection::class)->handle($this->staff, $order->items()->sole(), Money::fromDecimal('5200.00', Currency::BDT));

        $settlement = onlineProfitSettlement($order);

        expect($settlement->collection_channel)->toBe(CollectionChannel::Cod)
            ->and($settlement->cod_amount_collected->toDecimal())->toBe('5200.00')
            ->and($settlement->payment_collected_at)->toBeNull()
            ->and($settlement->reseller_earning->toDecimal())->toBe('200.00')
            ->and(LedgerEntry::query()->count())->toBe(1);
    });

    it('is never settled by the payment path: a cash order has no verified payment to collect from', function () {
        $order = onlineProfitPlace('cod');

        app(SynchronizeOnlineCollectionWithPayment::class)->handle($order->load('items'), $order->payment);

        expect(onlineProfitSettlement($order))->toBeNull();
    });
});

describe('a Partner-gateway order', function () {
    it('records the collection as external and never credits the Partner\'s wallet', function () {
        $order = onlineProfitPlace('online');
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        onlineProfitOwnedByPartner($payment->refresh());
        $payment->refresh()->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'pp-'.Str::random(8)])->save();
        app(ConfirmOrderPayment::class)->handle($payment->refresh());

        onlineProfitDeliver($order);

        $settlement = onlineProfitSettlement($order);

        expect($payment->refresh()->gateway_ownership)->toBe(GatewayOwnership::Partner)
            ->and($settlement->collection_channel)->toBe(CollectionChannel::PartnerGatewayExternal)
            ->and($settlement->eligible_at)->not->toBeNull()
            ->and($settlement->reseller_earning->toDecimal())->toBe('0.00')
            ->and($settlement->wallet_transaction_id)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0)
            ->and(onlineProfitWallet()?->total->toDecimal() ?? '0.00')->toBe('0.00');
    });

    it('cannot be given a wallet credit or an earning by the database', function () {
        $order = onlineProfitPlace('online');
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        onlineProfitOwnedByPartner($payment->refresh());
        $payment->refresh()->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'pp-'.Str::random(8)])->save();
        app(ConfirmOrderPayment::class)->handle($payment->refresh());
        onlineProfitDeliver($order);

        $row = onlineProfitSettlement($order);

        expect(fn () => DB::transaction(fn () => DB::table('order_proceeds_settlements')->where('id', $row->id)->update(['reseller_earning' => '200.00'])))
            ->toThrow(QueryException::class);
    });
});

describe('a payment with no frozen ownership', function () {
    it('is not reclassified: it records nothing, and its profit settles only as it always did', function () {
        $order = onlineProfitPlace('online');
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        onlineProfitWithoutOwnership($payment->refresh());
        $payment->refresh()->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'lg-'.Str::random(8)])->save();
        app(ConfirmOrderPayment::class)->handle($payment->refresh());

        onlineProfitDeliver($order);

        $settlement = onlineProfitSettlement($order);

        // Delivery recorded its own fact; nothing was guessed about the collection.
        expect($settlement->collection_channel)->toBeNull()
            ->and($settlement->payment_collected_at)->toBeNull()
            ->and($settlement->eligible_at)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0);

        // Staff can still confirm it as cash, exactly as before.
        app(RecordCodCollection::class)->handle($this->staff, $order->items()->sole(), Money::fromDecimal('5200.00', Currency::BDT));

        expect(onlineProfitSettlement($order)->collection_channel)->toBe(CollectionChannel::Cod)
            ->and(LedgerEntry::query()->count())->toBe(1);
    });
});

describe('what the database guarantees', function () {
    it('refuses an eligible line with neither collection fact', function () {
        $order = onlineProfitPlace('online');
        $item = $order->items()->sole();
        $settlement = OrderProceedsSettlement::create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'business_account_id' => $this->account->id,
            'currency_code' => 'BDT',
            'resale_amount' => Money::fromDecimal('5200.00', Currency::BDT),
            'recovered_amount' => Money::fromDecimal('5000.00', Currency::BDT),
            'created_at' => now(),
        ]);

        expect(fn () => DB::transaction(fn () => DB::table('order_proceeds_settlements')->where('id', $settlement->id)->update([
            'delivered_at' => now(), 'eligible_at' => now(), 'reseller_earning' => '200.00',
        ])))->toThrow(QueryException::class);
    });

    it('never lets one line carry both a cash and a payment collection', function () {
        $order = onlineProfitPlace('online');
        onlineProfitPay($order);
        $row = onlineProfitSettlement($order);

        expect(fn () => DB::transaction(fn () => DB::table('order_proceeds_settlements')->where('id', $row->id)->update([
            'cod_collected_at' => now(), 'cod_amount_collected' => '5200.00',
        ])))->toThrow(QueryException::class);
    });

    it('cannot change a collection fact once it is written', function () {
        $order = onlineProfitPlace('online');
        onlineProfitPay($order);
        $row = onlineProfitSettlement($order);

        foreach (['payment_collected_amount' => '1.00', 'collection_channel' => 'partner_gateway_external'] as $column => $value) {
            expect(fn () => DB::transaction(fn () => DB::table('order_proceeds_settlements')->where('id', $row->id)->update([$column => $value])))
                ->toThrow(QueryException::class);
        }

        expect(onlineProfitSettlement($order)->payment_collected_amount->toDecimal())->toBe('5200.00');
    });
});
