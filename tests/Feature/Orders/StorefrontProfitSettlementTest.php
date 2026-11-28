<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AuditStorefrontProfits;
use App\Domain\Order\Actions\ConfirmOrderPayment;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReleaseDueStorefrontProfits;
use App\Domain\Order\Actions\SettleStorefrontProfit;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\StorefrontProfitStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Order\Models\PartnerStorefrontProfitLine;
use App\Domain\Order\Models\PartnerStorefrontProfitReversal;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Notifications\Orders\StorefrontProfitUpdated;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * A Partner's profit on a Storefront order (D34, owner's decisions of 2026-11-25).
 *
 * Both kinds of Partner earn it. Non-Conditional, from Banij's central stock:
 * net selling amount less the platform price frozen on the line. Conditional,
 * from their own stock: net selling amount less what those units cost them,
 * first in, first out. Credited as withdrawable money exactly 24 hours after the
 * authoritative Delivered time, once the money is collected — and only once.
 */

beforeEach(function () {
    // Every scenario lives on one clock: orders are placed and paid the
    // morning of 1 November, delivered later that day, so the 24-hour hold is
    // measured from facts in a known order.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00:00'));
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    Http::fake(fn () => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']));

    $this->product = websiteTestProduct();

    $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'available' => 50]);

    $this->staff = testPlatformStaff(PlatformRole::OrderManager);
});

/**
 * A Partner of the given type with a live Storefront selling the product at `$price`.
 *
 * @return array{0: BusinessAccount, 1: Website}
 */
function storefrontProfitPartner(AccountType $type, string $price = '2600.00'): array
{
    $account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $account->forceFill(['account_type' => $type])->save();

    $website = Website::factory()->forAccount($account)->active()->create(['sms_verification_enabled' => false]);

    WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $account->id,
        'product_id' => test()->product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal($price, Currency::BDT),
        'published_at' => now(),
    ]);

    return [$account->refresh(), $website];
}

/**
 * A paid wholesale purchase that gives the Partner units of the product at a
 * known net cost: `$unit` each, less `$discount` on the line.
 */
function storefrontProfitPurchase(BusinessAccount $account, Product $product, int $quantity, string $unit, string $discount = '0.00', ?CarbonImmutable $paidAt = null): Order
{
    $order = Order::factory()->create(['business_account_id' => $account->id, 'paid_at' => $paidAt ?? now()]);
    $unitPrice = Money::fromDecimal($unit, Currency::BDT);
    $subtotal = $unitPrice->multipliedBy($quantity);
    $off = Money::fromDecimal($discount, Currency::BDT);

    $order->items()->create([
        'line_number' => 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'currency_code' => 'BDT',
        'unit_price' => $unitPrice,
        'line_subtotal' => $subtotal,
        'discount' => $off,
        'line_total' => $subtotal->minus($off),
    ]);

    app(ReceivePartnerPurchase::class)->handle($order->refresh());

    return $order;
}

/**
 * A Storefront order for `$quantity` units at the website's price, less an
 * order discount, cash on delivery or online.
 */
function storefrontProfitPlace(Website $website, int $quantity, string $unit, string $method = 'cod', string $discount = '0.00'): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    $subtotal = $money($unit)->multipliedBy($quantity);
    $off = $money($discount);

    [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
        deliveryLocation: deliveryTestLocation(),
        reference: 'SF-PR-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => $quantity]],
        claimedUnitPrices: [$money($unit)],
        claimedTotals: [
            'subtotal' => $subtotal,
            'discount' => $off,
            'shipping' => $money('0.00'),
            'tax' => $money('0.00'),
            'grand_total' => $subtotal->minus($off),
        ],
        paymentMethod: $method,
        sellerDiscount: $off->isPositive() ? $off : null,
    ));

    return $order->refresh();
}

/** The gateway confirms the online payment; the order is confirmed from it. */
function storefrontProfitPay(Order $order, GatewayOwnership $ownership = GatewayOwnership::Platform): void
{
    $payment = $order->payment;

    if ($ownership === GatewayOwnership::Partner) {
        // A payment the customer made on the Partner's own gateway (D36). Its
        // ownership is frozen at placement; this storefront has no gateway of
        // its own, so the frozen value is set past the set-once guard — only a
        // test may do this. The D36 receivable path has its own tests; here
        // only the profit's reading of the frozen ownership is under test.
        $configuration = PartnerGatewayConfiguration::create([
            'business_account_id' => $order->business_account_id,
            'website_id' => $order->website_id,
            'provider' => 'sslcommerz',
            'status' => 'active',
        ]);
        $version = PartnerGatewayConfigurationVersion::create([
            'partner_gateway_configuration_id' => $configuration->id,
            'business_account_id' => $order->business_account_id,
            'website_id' => $order->website_id,
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

        $payment->refresh()->transitionTo(PaymentStatus::Initiated)->save();
        $payment->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'pp-'.Str::random(8)])->save();
        app(SettleStorefrontProfit::class)->sync($order->refresh());

        return;
    }

    $payment->transitionTo(PaymentStatus::Initiated)->save();
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'sp-'.Str::random(8)])->save();

    app(ConfirmOrderPayment::class)->handle($payment->refresh());
}

/** The courier delivers: the delivery history records Delivered at `$at`. */
function storefrontProfitDeliver(Order $order, CarbonImmutable $at): void
{
    // What happens next (cash confirmed, returns) happens at the delivery.
    test()->travelTo($at);
    $order = $order->refresh();

    foreach ([OrderDeliveryStatus::CourierAssigned, OrderDeliveryStatus::Shipped, OrderDeliveryStatus::OutForDelivery] as $step) {
        $order->moveDeliveryTo($step, new StatusChange(reason: 'test', at: $at->subHour()), OrderStatusChangeSource::Staff);
    }

    $order->moveDeliveryTo(OrderDeliveryStatus::Delivered, new StatusChange(reason: 'test', at: $at), OrderStatusChangeSource::Staff);

    app(SettleStorefrontProfit::class)->sync($order->refresh());
}

function storefrontProfitConfirmCod(Order $order): PartnerStorefrontProfit
{
    $order = $order->refresh();

    return app(SettleStorefrontProfit::class)->confirmCodCollection(test()->staff, $order, $order->total);
}

function storefrontProfitSweep(CarbonImmutable $at): void
{
    test()->travelTo($at);
    app(ReleaseDueStorefrontProfits::class)->handle($at);
}

function storefrontProfitOf(Order $order): PartnerStorefrontProfit
{
    return PartnerStorefrontProfit::query()->where('order_id', $order->id)->firstOrFail();
}

/** Withdrawable money credited to the account for Storefront profit. */
function storefrontProfitCredited(BusinessAccount $account): string
{
    $wallet = Wallet::query()->where('business_account_id', $account->id)->first();

    if ($wallet === null) {
        return '0.00';
    }

    $sum = LedgerEntry::query()
        ->where('wallet_id', $wallet->id)
        ->whereIn('wallet_transaction_id', WalletTransaction::query()->where('idempotency_key', 'like', 'partner-storefront-profit%')->select('id'))
        ->get()
        ->reduce(fn (Money $carry, LedgerEntry $entry) => $carry->plus($entry->credit)->minus($entry->debit), Money::zero(Currency::BDT));

    return $sum->toDecimal();
}

describe('who earns, and how much', function () {
    it('credits a Non-Conditional Partner selling central stock on cash on delivery: selling price less the frozen wholesale price', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');

        // Wholesale ৳1,500 a unit — not the ৳2,500 suggested price, not the ৳0 base cost.
        expect($order->account_type)->toBe(AccountType::NonConditional)
            ->and($order->items->first()->product_cost->toDecimal())->toBe('3000.00');

        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);

        $profit = storefrontProfitOf($order);
        $line = PartnerStorefrontProfitLine::query()->where('order_item_id', $order->items->first()->id)->firstOrFail();
        expect($profit->status)->toBe(StorefrontProfitStatus::Holding)
            ->and($line->cost_basis)->toBe(PartnerStorefrontProfitLine::BASIS_WHOLESALE_PRICE)
            ->and($profit->cost_amount->toDecimal())->toBe('3000.00')
            ->and($profit->profit_amount->toDecimal())->toBe('2200.00')
            ->and($profit->release_at->equalTo($delivered->addHours(24)))->toBeTrue();

        storefrontProfitSweep($delivered->addHours(24));

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Released)
            ->and(storefrontProfitCredited($account))->toBe('2200.00');
    });

    it('credits a Non-Conditional Partner paid through Banij\'s gateway', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00', 'online');
        storefrontProfitPay($order);

        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Holding);

        storefrontProfitSweep($delivered->addHours(24));

        expect(storefrontProfitCredited($account))->toBe('2200.00');
    });

    it('credits a Conditional Partner selling their own stock on cash on delivery: selling price less the FIFO purchase cost', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '2200.00');
        storefrontProfitPurchase($account, $this->product, 3, '1500.00');
        $order = storefrontProfitPlace($website, 2, '2200.00');

        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);

        $line = PartnerStorefrontProfitLine::query()->where('order_item_id', $order->items->first()->id)->firstOrFail();
        expect($line->cost_basis)->toBe(PartnerStorefrontProfitLine::BASIS_FIFO_LOTS)
            ->and($line->cost_amount->toDecimal())->toBe('3000.00')
            ->and(storefrontProfitOf($order)->profit_amount->toDecimal())->toBe('1400.00');

        storefrontProfitSweep($delivered->addHours(24));

        expect(storefrontProfitCredited($account))->toBe('1400.00');
    });

    it('credits a Conditional Partner selling their own stock paid through Banij\'s gateway', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '2200.00');
        storefrontProfitPurchase($account, $this->product, 2, '1500.00');
        $order = storefrontProfitPlace($website, 1, '2200.00', 'online');
        storefrontProfitPay($order);

        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitSweep($delivered->addHours(24));

        expect(storefrontProfitCredited($account))->toBe('700.00');
    });

    it('takes the oldest purchase lots first and keeps each lot\'s exact cost', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '2200.00');
        storefrontProfitPurchase($account, $this->product, 1, '1000.00', paidAt: CarbonImmutable::parse('2026-10-01'));
        storefrontProfitPurchase($account, $this->product, 5, '1200.00', paidAt: CarbonImmutable::parse('2026-10-05'));

        $order = storefrontProfitPlace($website, 2, '2200.00');
        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);

        // One unit at ৳1,000 and one at ৳1,200 — never an average.
        expect(storefrontProfitOf($order)->cost_amount->toDecimal())->toBe('2200.00')
            ->and(storefrontProfitOf($order)->profit_amount->toDecimal())->toBe('2200.00');
    });

    it('takes the discount off the selling side and leaves tax and delivery out', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '1500.00');
        storefrontProfitPurchase($account, $this->product, 1, '1000.00');
        $order = storefrontProfitPlace($website, 1, '1500.00', discount: '100.00');

        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);

        // ৳1,500 less the ৳100 discount is ৳1,400; less the ৳1,000 lot: ৳400.
        expect(storefrontProfitOf($order)->selling_amount->toDecimal())->toBe('1400.00')
            ->and(storefrontProfitOf($order)->profit_amount->toDecimal())->toBe('400.00');
    });

    it('takes a purchase discount off the acquisition cost', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '1500.00');
        storefrontProfitPurchase($account, $this->product, 2, '1000.00', discount: '200.00');
        $order = storefrontProfitPlace($website, 1, '1500.00');

        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);

        // The lot cost ৳1,800 for 2 units: ৳900 each.
        expect(storefrontProfitOf($order)->cost_amount->toDecimal())->toBe('900.00')
            ->and(storefrontProfitOf($order)->profit_amount->toDecimal())->toBe('600.00');
    });

    it('credits only the Partner who owns the Storefront', function () {
        [$seller, $website] = storefrontProfitPartner(AccountType::NonConditional);
        [$other] = storefrontProfitPartner(AccountType::NonConditional);

        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);
        storefrontProfitSweep($delivered->addHours(24));

        expect(storefrontProfitCredited($seller))->toBe('2200.00')
            ->and(storefrontProfitCredited($other))->toBe('0.00')
            ->and(storefrontProfitOf($order)->business_account_id)->toBe($seller->id);
    });
});

describe('the formula and its breakdown', function () {
    it('gives the Partner the margin over the wholesale price and the delivery charge', function (int $quantity, int $deliveryFee, string $delivery, string $customerTotal, string $base, string $banij, string $partner) {
        FeeRule::query()->where('fee_type', FeeType::WebsiteDelivery->value)->delete();
        websiteTestFee(FeeType::WebsiteDelivery, $deliveryFee);
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '1500.00');
        storefrontProfitPurchase($account, $this->product, $quantity, '1000.00');

        $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
        $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
        $subtotal = $money('1500.00')->multipliedBy($quantity);
        [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
            deliveryLocation: deliveryTestLocation(), reference: 'SF-DL-'.Str::upper(Str::random(6)), idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
            shippingAddress: $address, billingAddress: $address,
            items: [['sku' => $this->product->sku, 'quantity' => $quantity]], claimedUnitPrices: [$money('1500.00')],
            claimedTotals: ['subtotal' => $subtotal, 'discount' => $money('0.00'), 'shipping' => $money($delivery), 'tax' => $money('0.00'), 'grand_total' => $money($customerTotal)],
            paymentMethod: 'cod',
        ));

        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);

        $profit = storefrontProfitOf($order);
        expect($profit->breakdown['customer_total'])->toBe($customerTotal)
            ->and($profit->breakdown['delivery_charge_to_partner'])->toBe($delivery)
            ->and($profit->breakdown['product_base_price_total'])->toBe($base)
            ->and($profit->breakdown['banij_receivable'])->toBe($banij)
            ->and($profit->breakdown['partner_profit'])->toBe($partner)
            ->and($profit->profit_amount->toDecimal())->toBe($partner);
    })->with([
        '৳1,500 sold, ৳1,000 base, ৳100 delivery' => [1, 10000, '100.00', '1600.00', '1000.00', '1000.00', '600.00'],
        'no delivery charge' => [1, 0, '0.00', '1500.00', '1000.00', '1000.00', '500.00'],
        'two units, ৳100 delivery' => [2, 10000, '100.00', '3100.00', '2000.00', '2000.00', '1100.00'],
    ]);

    it('gives no delivery charge on an order whose every unit came back', function () {
        FeeRule::query()->where('fee_type', FeeType::WebsiteDelivery->value)->delete();
        websiteTestFee(FeeType::WebsiteDelivery, 10000);
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);

        $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
        $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
        [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
            deliveryLocation: deliveryTestLocation(), reference: 'SF-RT-'.Str::upper(Str::random(6)), idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
            shippingAddress: $address, billingAddress: $address,
            items: [['sku' => $this->product->sku, 'quantity' => 1]], claimedUnitPrices: [$money('2600.00')],
            claimedTotals: ['subtotal' => $money('2600.00'), 'discount' => $money('0.00'), 'shipping' => $money('100.00'), 'tax' => $money('0.00'), 'grand_total' => $money('2700.00')],
            paymentMethod: 'cod',
        ));
        storefrontProfitReturn($order, 1, 'completed', received: 1);

        app(SettleStorefrontProfit::class)->sync($order->refresh());

        expect(storefrontProfitOf($order)->breakdown['delivery_charge_to_partner'])->toBe('0.00')
            ->and(storefrontProfitOf($order)->profit_amount->toDecimal())->toBe('0.00');
    });
});

describe('the 24-hour hold', function () {
    it('counts the 24 hours from the later of delivery and collection', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);

        $collected = $delivered->addHours(10);
        $this->travelTo($collected);
        storefrontProfitConfirmCod($order);

        expect(storefrontProfitOf($order)->release_at->equalTo($collected->addHours(24)))->toBeTrue();

        storefrontProfitSweep($delivered->addHours(24));
        expect(storefrontProfitCredited($account))->toBe('0.00');

        storefrontProfitSweep($collected->addHours(24));
        expect(storefrontProfitCredited($account))->toBe('2200.00');
    });

    it('shows the profit as pending once the order is dispatched, before anything is credited', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');

        foreach ([OrderDeliveryStatus::CourierAssigned, OrderDeliveryStatus::Shipped] as $step) {
            $order->refresh()->moveDeliveryTo($step, new StatusChange(reason: 'test'), OrderStatusChangeSource::Staff);
        }
        app(SettleStorefrontProfit::class)->sync($order->refresh());

        $profit = storefrontProfitOf($order);
        expect($profit->status)->toBe(StorefrontProfitStatus::AwaitingFacts)
            ->and($profit->status_reason)->toBe('awaiting_delivery')
            ->and($profit->profit_amount->toDecimal())->toBe('2200.00')
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });

    it('keeps a delivered cash-on-delivery order awaiting its collection', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '2200.00');
        storefrontProfitPurchase($account, $this->product, 1, '1500.00');
        $order = storefrontProfitPlace($website, 1, '2200.00');
        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::AwaitingFacts)
            ->and(storefrontProfitOf($order)->status_reason)->toBe('awaiting_collection');
    });

    it('credits nothing before 24 hours have passed since Delivered', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);

        storefrontProfitSweep($delivered->addHours(24)->subSecond());

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Holding)
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });

    it('credits nothing without the collection, however long after delivery', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);

        storefrontProfitSweep($delivered->addDays(5));

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::AwaitingFacts)
            ->and(storefrontProfitOf($order)->status_reason)->toBe('awaiting_collection')
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });

    it('credits once, under the order\'s own key, however often the sweep runs', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);

        storefrontProfitSweep($delivered->addHours(24));
        storefrontProfitSweep($delivered->addHours(25));
        app(SettleStorefrontProfit::class)->sync($order->refresh());
        app(SettleStorefrontProfit::class)->release(storefrontProfitOf($order)->id, $delivered->addHours(26));

        expect(WalletTransaction::query()->where('idempotency_key', 'partner-storefront-profit:'.$order->public_id)->count())->toBe(1)
            ->and(storefrontProfitCredited($account))->toBe('2200.00');

        $entry = LedgerEntry::query()->where('wallet_transaction_id', storefrontProfitOf($order)->wallet_transaction_id)->firstOrFail();
        expect($entry->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($entry->type)->toBe(LedgerTransactionType::SalesCredit);
    });
});

describe('the Partner\'s own gateway', function () {
    it('reports the profit and never credits it', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00', 'online');
        storefrontProfitPay($order, GatewayOwnership::Partner);

        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitSweep($delivered->addDays(3));

        $profit = storefrontProfitOf($order);
        expect($profit->status)->toBe(StorefrontProfitStatus::ReportedOnly)
            ->and($profit->profit_amount->toDecimal())->toBe('2200.00')
            ->and($profit->wallet_transaction_id)->toBeNull()
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });
});

describe('what blocks it, and what takes it back', function () {
    it('holds a profit while a return is open, and recalculates on a partial return before release', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '2200.00');
        storefrontProfitPurchase($account, $this->product, 2, '1500.00');
        $order = storefrontProfitPlace($website, 2, '2200.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);

        $return = storefrontProfitReturn($order, 1, 'requested');
        app(SettleStorefrontProfit::class)->sync($order->refresh());
        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Blocked)
            ->and(storefrontProfitOf($order)->status_reason)->toBe('return_open');

        storefrontProfitSweep($delivered->addHours(30));
        expect(storefrontProfitCredited($account))->toBe('0.00');

        DB::table('order_returns')->where('id', $return)->update(['status' => 'completed', 'received_at' => now()]);
        DB::table('order_return_items')->where('order_return_id', $return)->update(['received_quantity' => 1]);
        storefrontProfitSweep($delivered->addHours(31));

        // One unit kept: ৳2,200 less ৳1,500.
        expect(storefrontProfitOf($order)->profit_amount->toDecimal())->toBe('700.00')
            ->and(storefrontProfitCredited($account))->toBe('700.00');
    });

    it('takes back the returned units\' profit after release by a compensating debit', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);
        storefrontProfitSweep($delivered->addHours(24));
        expect(storefrontProfitCredited($account))->toBe('2200.00');

        storefrontProfitReturn($order, 1, 'completed', received: 1);
        app(SettleStorefrontProfit::class)->sync($order->refresh());

        // One unit kept: ৳2,600 less ৳1,500 is ৳1,100; the other ৳1,100 comes back.
        $reversal = PartnerStorefrontProfitReversal::query()->sole();
        expect($reversal->status)->toBe(PartnerStorefrontProfitReversal::STATUS_POSTED)
            ->and($reversal->amount->toDecimal())->toBe('1100.00')
            ->and(storefrontProfitCredited($account))->toBe('1100.00')
            ->and(storefrontProfitOf($order)->reversed_amount->toDecimal())->toBe('1100.00');
    });

    it('never takes the Wallet negative: an uncovered reversal is owed and taken when it can be', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);
        storefrontProfitSweep($delivered->addHours(24));

        // The Partner spends the profit.
        $wallet = app(OpenWallet::class)->handle($account, Currency::BDT);
        app(WalletService::class)->debit($wallet, LedgerTransactionType::ReturnAdjustment, Money::fromDecimal('2200.00', Currency::BDT), new PostingContext(source: 'test', description: 'spent', idempotencyKey: 'spent-'.Str::random(6), reason: 'Spent elsewhere.'), BalanceType::Withdrawable);

        storefrontProfitReturn($order, 2, 'completed', received: 2);
        app(SettleStorefrontProfit::class)->sync($order->refresh());

        $reversal = PartnerStorefrontProfitReversal::query()->sole();
        expect($reversal->status)->toBe(PartnerStorefrontProfitReversal::STATUS_OWED)
            ->and($wallet->refresh()->total->isNegative())->toBeFalse();

        app(WalletService::class)->credit($wallet, LedgerTransactionType::SalesCredit, Money::fromDecimal('5000.00', Currency::BDT), new PostingContext(source: 'test', description: 'later sale', idempotencyKey: 'later-'.Str::random(6)), BalanceType::Withdrawable);
        storefrontProfitSweep($delivered->addDays(3));

        expect($reversal->refresh()->status)->toBe(PartnerStorefrontProfitReversal::STATUS_POSTED)
            ->and(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Reversed);
    });

    it('earns nothing on a cancelled order', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);

        DB::table('orders')->where('id', $order->id)->update(['cancelled_at' => now()]);
        storefrontProfitSweep(CarbonImmutable::parse('2026-11-03 10:00:00'));
        app(SettleStorefrontProfit::class)->sync($order->refresh());

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Cancelled)
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });

    it('sends a profit below zero to review and debits nothing', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '1400.00');
        storefrontProfitPurchase($account, $this->product, 1, '1600.00');
        $order = storefrontProfitPlace($website, 1, '1400.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);
        storefrontProfitSweep($delivered->addDays(2));

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::ReviewRequired)
            ->and(storefrontProfitOf($order)->status_reason)->toBe('negative_profit')
            ->and(WalletTransaction::query()->where('idempotency_key', 'like', 'partner-storefront-profit%')->count())->toBe(0);
    });

    it('writes no Wallet entry for a profit of exactly zero', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '1500.00');
        storefrontProfitPurchase($account, $this->product, 1, '1500.00');
        $order = storefrontProfitPlace($website, 1, '1500.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);
        storefrontProfitSweep($delivered->addDays(2));

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::NoProfit)
            ->and(WalletTransaction::query()->where('idempotency_key', 'like', 'partner-storefront-profit%')->count())->toBe(0);
    });

    it('sends a collection that does not match the order total to review', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        app(SettleStorefrontProfit::class)->confirmCodCollection($this->staff, $order->refresh(), Money::fromDecimal('5000.00', Currency::BDT));
        storefrontProfitSweep($delivered->addDays(2));

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::ReviewRequired)
            ->and(storefrontProfitOf($order)->status_reason)->toBe('collection_mismatch')
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });
});

describe('failures and staff', function () {
    it('marks a technical failure failed, and only a failed credit can be retried', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);

        $settle = app(SettleStorefrontProfit::class);
        expect(fn () => $settle->retry($this->staff, storefrontProfitOf($order)))->toThrow(InvalidArgumentException::class);

        // The Wallet cannot be opened: a frozen account wallet refuses the credit.
        $this->mock(OpenWallet::class)->shouldReceive('handle')->andThrow(new RuntimeException('wallet store unavailable'));
        app()->forgetInstance(SettleStorefrontProfit::class);
        app(SettleStorefrontProfit::class)->release(storefrontProfitOf($order)->id, $delivered->addHours(24));

        $profit = storefrontProfitOf($order);
        expect($profit->status)->toBe(StorefrontProfitStatus::Failed)
            ->and($profit->attempts)->toBe(1)
            ->and($profit->failure_message)->toContain('wallet store unavailable');

        app()->forgetInstance(OpenWallet::class);
        app()->forgetInstance(SettleStorefrontProfit::class);
        $this->travelTo($delivered->addHours(25));
        app(SettleStorefrontProfit::class)->retry($this->staff, $profit);

        expect(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Released)
            ->and(storefrontProfitCredited($account))->toBe('2200.00');
    });

    it('tells the Partner the pending amount and release time without any customer details', function () {
        Notification::fake();
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);

        Notification::assertSentTo($account->owner, StorefrontProfitUpdated::class, function (StorefrontProfitUpdated $notification) use ($account) {
            $payload = json_encode($notification->toArray($account->owner));

            return $notification->event === StorefrontProfitUpdated::PENDING
                && $notification->releaseAt !== null
                && ! str_contains((string) $payload, 'Ayesha')
                && ! str_contains((string) $payload, '+88017');
        });
    });
});

describe('the account type and the historical audit', function () {
    it('freezes the account type on a Storefront order when it is placed', function () {
        [, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 1, '2600.00');

        expect($order->account_type)->toBe(AccountType::NonConditional);
    });

    it('audits read-only: nothing is written and nothing credited', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::NonConditional);
        $order = storefrontProfitPlace($website, 2, '2600.00');
        storefrontProfitDeliver($order, CarbonImmutable::parse('2026-11-01 10:00:00'));
        storefrontProfitConfirmCod($order);
        $before = storefrontProfitOf($order)->updated_at;

        DB::table('partner_storefront_profits')->where('order_id', $order->id)->update(['status' => 'awaiting_facts']);
        $rows = app(AuditStorefrontProfits::class)->handle(apply: false);

        expect($rows->firstWhere('order', $order->reference)['status'])->toBe('holding')
            ->and(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::AwaitingFacts)
            ->and(storefrontProfitCredited($account))->toBe('0.00');
    });

    it('prices a historical sale bought at more than one price at the product\'s wholesale price', function () {
        [$account, $website] = storefrontProfitPartner(AccountType::Conditional, '2200.00');
        storefrontProfitPurchase($account, $this->product, 1, '1000.00', paidAt: CarbonImmutable::parse('2026-10-01'));
        storefrontProfitPurchase($account, $this->product, 1, '1200.00', paidAt: CarbonImmutable::parse('2026-10-02'));
        $order = storefrontProfitPlace($website, 1, '2200.00');

        // A sale from before cost lots existed: its hold took no lot.
        storefrontProfitForgetLots($order);

        $delivered = CarbonImmutable::parse('2026-11-01 10:00:00');
        storefrontProfitDeliver($order, $delivered);
        storefrontProfitConfirmCod($order);
        app(AuditStorefrontProfits::class)->handle(apply: true);
        storefrontProfitSweep($delivered->addDays(3));

        // Bought at ৳1,000 and at ৳1,200: no one purchase price, so the
        // catalogue's ৳1,500 wholesale price — never a review for want of a cost.
        $line = PartnerStorefrontProfitLine::query()->where('order_item_id', $order->items->first()->id)->firstOrFail();

        expect($line->cost_basis)->toBe(PartnerStorefrontProfitLine::BASIS_CATALOGUE_WHOLESALE_PRICE)
            ->and($line->cost_amount->toDecimal())->toBe('1500.00')
            ->and($line->unproven_reason)->toBeNull()
            ->and(storefrontProfitOf($order)->status)->toBe(StorefrontProfitStatus::Released)
            ->and(storefrontProfitCredited($account))->toBe('700.00');
    });
});

/**
 * A return of `$quantity` units of the order's first line, at `$status`.
 */
function storefrontProfitReturn(Order $order, int $quantity, string $status, int $received = 0): int
{
    $item = $order->items->first();

    $returnId = DB::table('order_returns')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'reference' => 'RT-'.Str::upper(Str::random(8)),
        'order_id' => $order->id,
        'business_account_id' => $order->business_account_id,
        'website_id' => $order->website_id,
        'source' => 'staff',
        'status' => $status,
        'reason' => 'Customer changed their mind',
        'requested_at' => now(),
        'received_at' => $status === 'requested' ? null : now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_return_items')->insert([
        'public_id' => (string) Str::ulid(),
        'order_return_id' => $returnId,
        'order_item_id' => $item->id,
        'quantity' => $quantity,
        'approved_quantity' => $quantity,
        'received_quantity' => $received,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $returnId;
}

/**
 * Make a sale look as if it was placed before cost lots existed: its hold took
 * nothing from a lot. Written past the append-only guard on purpose — only a
 * test may do this.
 */
function storefrontProfitForgetLots(Order $order): void
{
    $reservation = $order->items->first()->stock_reservation_id;
    $pdo = DB::connection()->getPdo();
    $pdo->exec('ALTER TABLE partner_stock_lot_movements DISABLE TRIGGER partner_stock_lot_movements_no_delete');
    DB::table('partner_stock_lot_movements')->where('stock_reservation_id', $reservation)->delete();
    $pdo->exec('ALTER TABLE partner_stock_lot_movements ENABLE TRIGGER partner_stock_lot_movements_no_delete');
}
