<?php

use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockAllocations;
use App\Domain\Order\Actions\EvaluateOrderProceedsEligibility;
use App\Domain\Order\Actions\RecordCodCollection;
use App\Domain\Order\Actions\ReverseOrderProceedsSettlement;
use App\Domain\Order\Actions\SynchronizeOrderProceedsWithDelivery;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\OrderReturnItem;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A Non-Conditional account sells straight from the catalogue: it need not buy a
 * product to publish it, the order is filled from central stock, and Feriwala
 * recovers the product cost from the sale's proceeds. A Conditional account is
 * unchanged — it must buy first.
 */
beforeEach(function () {
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->api = storefrontCredential($this->website, [
        CredentialScope::CatalogRead, CredentialScope::InventoryRead, CredentialScope::OrdersWrite, CredentialScope::OrdersRead,
    ]);
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->product = websiteTestProduct();
    websiteTestFee(FeeType::WebsiteDelivery, 6000);
});

function nonConditionalMake(BusinessAccount $account): void
{
    $account->forceFill(['account_type' => AccountType::NonConditional])->save();
}

function nonConditionalCentralStock(int $units): StockItem
{
    return StockItem::create([
        'warehouse_id' => test()->warehouse->id,
        'product_id' => test()->product->id,
        'available' => $units,
    ]);
}

function nonConditionalPublish(): TestResponse
{
    return test()->actingAs(test()->account->owner)
        ->put(route('catalog.mine.websites.update', [test()->product->slug, test()->website->public_id]), ['published' => true]);
}

/**
 * The store's own price for the product, in Taka, as the owner would set it.
 */
function nonConditionalPriceAt(string $price): void
{
    WebsiteProduct::query()->where('website_id', test()->website->id)->update(['price' => $price]);
}

/**
 * The order is delivered and the cash for its one line is collected: the two
 * facts that make a line's profit real.
 */
function nonConditionalSettle(Order $order, OrderItem $item, string $collected): void
{
    app(SynchronizeOrderProceedsWithDelivery::class)->handle($order->load('items'), OrderDeliveryStatus::Delivered);
    app(RecordCodCollection::class)->handle(test()->account->owner, $item, Money::fromDecimal($collected, Currency::BDT));
}

/**
 * `$units` of the line come back, as a received return line.
 */
function nonConditionalReturnedUnits(Order $order, OrderItem $item, int $units): OrderReturnItem
{
    $return = OrderReturn::create([
        'order_id' => $order->id,
        'business_account_id' => $order->business_account_id,
        'website_id' => $order->website_id,
        'source' => OrderStatusChangeSource::Staff,
        'status' => ReturnStatus::Requested,
        'reason' => ReturnReason::Other,
        'requested_at' => now(),
    ]);

    return $return->items()->create([
        'order_item_id' => $item->id,
        'quantity' => $units,
        'approved_quantity' => $units,
        'received_quantity' => $units,
    ]);
}

function nonConditionalPlaceOrder(string $unit = '2500.00'): TestResponse
{
    $money = fn (string $amount) => ['amount' => $amount, 'currency' => 'BDT'];
    $subtotal = bcmul($unit, '2', 2);

    $body = [
        'storefront_order_reference' => 'SF-NC-'.Str::upper(Str::random(5)),
        'customer' => ['name' => 'Ayesha Rahman', 'phone' => '01712-345678', 'is_guest' => true],
        'shipping_address' => ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'],
        'items' => [['sku' => test()->product->sku, 'quantity' => 2, 'unit_price' => $money($unit)]],
        'totals' => [
            'subtotal' => $money($subtotal),
            'discount' => $money('0.00'),
            'shipping' => $money('60.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money(bcadd($subtotal, '60.00', 2)),
        ],
        'payment' => ['method' => 'cod'],
    ];

    return storefrontCall(
        test()->api[0],
        test()->api[1],
        'orders',
        method: 'POST',
        body: (string) json_encode($body),
        overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']],
    );
}

describe('publishing without buying', function () {
    it('lets a Non-Conditional account publish a product it never bought and shows central stock', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);

        nonConditionalPublish()->assertSessionHasNoErrors();

        expect(WebsiteProduct::query()->sole()->status)->toBe(WebsiteProductStatus::Published);

        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->public_id)
            ->assertJsonPath('data.0.variants.0.availability.quantity', 20);
    });

    it('shows and sells stock allocated to the account, as the ERP catalogue does', function () {
        nonConditionalMake($this->account);
        $item = nonConditionalCentralStock(10);
        app(StockAllocations::class)->allocate($item, $this->account, 10);

        nonConditionalPublish()->assertSessionHasNoErrors();

        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()
            ->assertJsonPath('data.0.variants.0.availability.in_stock', true)
            ->assertJsonPath('data.0.variants.0.availability.quantity', 10);

        nonConditionalPlaceOrder()->assertSuccessful();
    });

    it('still has nothing to publish when central stock is empty', function () {
        nonConditionalMake($this->account);

        nonConditionalPublish()->assertSessionHasErrors('website');

        expect(WebsiteProduct::query()->where('status', WebsiteProductStatus::Published)->count())->toBe(0);
    });

    it('keeps a Conditional account bound to what it bought, whatever central stock holds', function () {
        nonConditionalCentralStock(20);

        nonConditionalPublish()->assertSessionHasErrors('website');

        expect(WebsiteProduct::query()->count())->toBe(0);
    });
});

describe('selling it', function () {
    it('fills the order from central stock and snapshots what Feriwala is owed', function () {
        nonConditionalMake($this->account);
        $central = nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();

        nonConditionalPlaceOrder()->assertCreated();

        $item = websiteTestOrders()->sole()->items()->sole();

        expect($item->stockReservation->isPartnerSourced())->toBeFalse()
            ->and($item->product_cost?->toDecimal())->toBe('3000.00')
            ->and($central->refresh()->available)->toBe(18);
    });

    it('sells the account\'s own units first and recovers no cost for them', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        websiteTestOwns($this->account, $this->product, 20);
        nonConditionalPublish()->assertSessionHasNoErrors();

        nonConditionalPlaceOrder()->assertCreated();

        $item = websiteTestOrders()->sole()->items()->sole();

        expect($item->stockReservation->isPartnerSourced())->toBeTrue()
            ->and($item->product_cost)->toBeNull();
    });

    it('credits the reseller the price the store sold at above the suggested price, as withdrawable money', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPriceAt('3000.00');
        nonConditionalPlaceOrder('3000.00')->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        nonConditionalSettle($order, $item, '6000.00');

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();
        $entry = LedgerEntry::query()->where('wallet_transaction_id', $settlement->wallet_transaction_id)->sole();
        $wallet = Wallet::query()->where('business_account_id', $this->account->id)->sole();

        // 2 units sold at ৳3,000, suggested ৳2,500: the platform keeps ৳5,000
        // and the partner earns the ৳1,000 above it. The product's base cost
        // plays no part.
        expect($item->platform_retained_amount?->toDecimal())->toBe('5000.00')
            ->and($settlement->recovered_amount->toDecimal())->toBe('5000.00')
            ->and($settlement->reseller_earning?->toDecimal())->toBe('1000.00')
            ->and($settlement->flagged_for_review)->toBeFalse()
            ->and($entry->type)->toBe(LedgerTransactionType::SalesCredit)
            ->and($entry->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($wallet->withdrawableHolding()->toDecimal())->toBe('1000.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00');
    });

    it('treats a store that sold at exactly the suggested price as a valid zero profit', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPlaceOrder()->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        nonConditionalSettle($order, $item, '5000.00');

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

        expect($settlement->reseller_earning?->toDecimal())->toBe('0.00')
            ->and($settlement->flagged_for_review)->toBeFalse()
            ->and($settlement->wallet_transaction_id)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('credits nothing, and flags it, when the store sold below the suggested price', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPriceAt('2100.00');
        nonConditionalPlaceOrder('2100.00')->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        nonConditionalSettle($order, $item, '4200.00');

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

        expect($settlement->flagged_for_review)->toBeTrue()
            ->and($settlement->wallet_transaction_id)->toBeNull()
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('never pays a profit twice, however often the settlement is asked for', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPriceAt('3000.00');
        nonConditionalPlaceOrder('3000.00')->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        nonConditionalSettle($order, $item, '6000.00');

        $eligibility = app(EvaluateOrderProceedsEligibility::class);
        $eligibility->markDelivered($item);
        $eligibility->markCodCollected($item, Money::fromDecimal('6000.00', Currency::BDT));

        expect(LedgerEntry::query()->count())->toBe(1)
            ->and(Wallet::query()->where('business_account_id', $this->account->id)->sole()->total->toDecimal())->toBe('1000.00');
    });

    it('measures the profit from the suggested price the order was placed at, not a later one', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPriceAt('3000.00');
        nonConditionalPlaceOrder('3000.00')->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        $this->product->forceFill(['suggested_selling_price' => Money::fromDecimal('2800.00', Currency::BDT)])->save();

        nonConditionalSettle($order, $item, '6000.00');

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

        expect($item->refresh()->platform_retained_amount?->toDecimal())->toBe('5000.00')
            ->and($settlement->reseller_earning?->toDecimal())->toBe('1000.00');
    });

    it('refuses an order at a price the platform\'s pricing rules no longer allow', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();

        // Below the product's ৳2,000 floor, whatever it was when it was listed.
        nonConditionalPriceAt('1900.00');

        nonConditionalPlaceOrder('1900.00')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'price_outside_rules');

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('takes back the profit on returned units with a compensating entry out of the same kind of money', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPriceAt('3000.00');
        nonConditionalPlaceOrder('3000.00')->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        nonConditionalSettle($order, $item, '6000.00');

        // One of the two units comes back.
        $returnItem = nonConditionalReturnedUnits($order, $item, 1);

        $reversal = app(ReverseOrderProceedsSettlement::class)->forReturn($returnItem);

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();
        $wallet = Wallet::query()->where('business_account_id', $this->account->id)->sole();
        $entry = LedgerEntry::query()->where('wallet_transaction_id', $reversal?->id)->sole();

        expect($reversal)->not->toBeNull()
            ->and($entry->balance_type)->toBe(BalanceType::Withdrawable)
            ->and($entry->debit->toDecimal())->toBe('500.00')
            ->and($settlement->reversed_amount->toDecimal())->toBe('500.00')
            ->and($wallet->total->toDecimal())->toBe('500.00')
            // The original credit stands exactly as it was.
            ->and(LedgerEntry::query()->count())->toBe(2);

        // Asked again, it answers with the same reversal and takes nothing more.
        expect(app(ReverseOrderProceedsSettlement::class)->forReturn($returnItem)?->id)->toBe($reversal->id)
            ->and($wallet->refresh()->total->toDecimal())->toBe('500.00');
    });

    it('never takes back more than was credited across partial returns', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPriceAt('3000.00');
        nonConditionalPlaceOrder('3000.00')->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        nonConditionalSettle($order, $item, '6000.00');

        foreach ([1, 1] as $units) {
            app(ReverseOrderProceedsSettlement::class)->forReturn(nonConditionalReturnedUnits($order, $item, $units));
        }

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

        expect($settlement->reversed_amount->toDecimal())->toBe('1000.00')
            ->and(Wallet::query()->where('business_account_id', $this->account->id)->sole()->total->toDecimal())->toBe('0.00');
    });

    it('refuses a collection on a line that deferred no cost', function () {
        nonConditionalMake($this->account);
        websiteTestOwns($this->account, $this->product, 20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPlaceOrder()->assertCreated();

        $item = websiteTestOrders()->sole()->items()->sole();

        expect(fn () => app(RecordCodCollection::class)->handle($this->account->owner, $item, Money::fromDecimal('5000.00', Currency::BDT)))
            ->toThrow(InvalidArgumentException::class);
    });
});
