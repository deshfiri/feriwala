<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\CancelUnpaidOrder;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\UnpaidOrderCancellation;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Exceptions\WebsiteOrderRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\WebsiteOrderPayload;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Supplier\Actions\RecordSupplierPayableDelivery;
use App\Domain\Supplier\Actions\SetSupplierOfferRates;
use App\Domain\Supplier\Actions\SuspendSupplierOffer;
use App\Domain\Supplier\Enums\PayableStatus;
use App\Domain\Supplier\Enums\SupplierStatus;
use App\Domain\Supplier\Models\Supplier;
use App\Domain\Supplier\Models\SupplierOffer;
use App\Domain\Supplier\Models\SupplierOfferStock;
use App\Domain\Supplier\Models\SupplierPayable;
use App\Domain\Supplier\Models\SupplierPayableReversal;
use App\Domain\Supplier\Models\SupplierStockMovement;
use App\Domain\Supplier\SupplierStockLedger;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Order allocation to a Supplier's Admin-chosen preferred offer, the
 * reservation of the Supplier's own stock inside the one reservation
 * lifecycle, the payable it accrues, and the leak boundaries around all
 * three (D25, P13-21, P13-22, P13-28).
 *
 * Nothing here modifies the reservation lifecycle for a central-stock order;
 * one such line is included in the multi-line test to prove that directly.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    $settings->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    // Mutated per call by supplierOrderPay() to the order actually being
    // settled — SettlePayment refuses a gateway-reported amount that does
    // not match the payment, so a fixed figure would only work for one quantity.
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

    [$this->credential, $this->secret] = storefrontCredential($this->website, [
        CredentialScope::OrdersWrite, CredentialScope::OrdersRead, CredentialScope::CustomersWrite,
    ]);

    $this->supplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
    $this->product = websiteTestProduct();
    $this->offer = supplierTestOffer($this->supplier, $this->product, supplierRate: '1000.00', platformRate: '1300.00', preferred: true);
    supplierTestOfferPriceVersion($this->offer);

    // Dropshipping's own selling price, independent of the Supplier's or
    // Platform Rate — exactly as an ordinary website product is priced.
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
});

/**
 * A website order for `$quantity` units, placed directly through the action
 * — matching the real HTTP path's own quote, but without the storefront
 * signature ceremony.
 */
function supplierOrderPlace(WebsiteProduct $selection, int $quantity = 1, string $method = 'online', ?string $reference = null): Order
{
    $unit = $selection->price_minor->minorUnits;
    $subtotal = $unit * $quantity;
    $delivery = 6000;

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: $reference ?? 'SF-ALLOC-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Karim Hossain', mobile: '+8801'.random_int(100000000, 999999999)),
        shippingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
        billingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
        items: [['sku' => $selection->product->sku, 'quantity' => $quantity]],
        claimedUnitPrices: [Money::of($unit, Currency::BDT)],
        claimedTotals: [
            'subtotal' => Money::of($subtotal, Currency::BDT),
            'discount' => Money::of(0, Currency::BDT),
            'shipping' => Money::of($delivery, Currency::BDT),
            'tax' => Money::of(0, Currency::BDT),
            'grand_total' => Money::of($subtotal + $delivery, Currency::BDT),
        ],
        paymentMethod: $method,
    ));

    return $order->refresh();
}

/**
 * @return array<string, string>
 */
function supplierOrderIpn(string $reference): array
{
    $fields = ['tran_id' => $reference, 'val_id' => 'val-1', 'status' => 'VALID'];
    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed))),
    ];
}

function supplierOrderPay(Order $order): void
{
    $payment = $order->refresh()->payment()->firstOrFail();

    // The gateway is asked to confirm this exact payment's amount — set here
    // so the fake in beforeEach answers with the figure this order actually
    // comes to, whatever quantity a given test placed.
    test()->validation['currency_amount'] = $payment->amount_minor->toDecimal();
    test()->validation['currency_type'] = $payment->amount_minor->currency->value;

    test()->post(route('webhooks.payment', 'sslcommerz'), supplierOrderIpn((string) $payment->reference));
}

/**
 * What fulfilment will do once it exists (§20, P6-17): move the order to
 * Delivered, and move its Supplier units from Processing to Sold — by hand,
 * exactly as the equivalent central-stock tests do for `stock_items`.
 */
function supplierOrderDeliver(Order $order, SupplierOffer $offer): void
{
    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test, as fulfilment will.'), OrderStatusChangeSource::System);
    }

    $item = $order->items()->sole();
    $stock = SupplierOfferStock::query()->where('supplier_offer_id', $offer->id)->firstOrFail();

    app(SupplierStockLedger::class)->move($stock, StockBucket::Processing, StockBucket::Sold, $item->quantity, 'delivered');
}

describe('allocation', function () {
    it('allocates a website order line to the Supplier\'s preferred active offer, reserves its own stock, and reveals none of it to the storefront', function () {
        $order = supplierOrderPlace($this->selection, 2);
        $item = $order->items()->sole();

        expect($item->supplier_id)->toBe($this->supplier->id)
            ->and($item->supplier_offer_id)->toBe($this->offer->id)
            ->and($item->supplier_allocated_quantity)->toBe(2)
            ->and($item->supplier_rate->toDecimal())->toBe('1000.00')
            ->and($item->platform_rate->toDecimal())->toBe('1300.00')
            ->and($item->platform_margin->toDecimal())->toBe('300.00')
            // Its own reservation, never a central stock_items row.
            ->and($item->stockReservation->isSupplierSourced())->toBeTrue()
            ->and(StockItem::query()->where('product_id', $this->product->id)->exists())->toBeFalse();

        $stock = SupplierOfferStock::query()->where('supplier_offer_id', $this->offer->id)->sole();
        expect($stock->quantity)->toBe(8)->and($stock->reserved_quantity)->toBe(2);

        // The storefront's own order-tracking payload — never named at any
        // depth: no Supplier id, no Supplier Rate, no platform margin (D12,
        // D25). Built by the same class the real endpoint answers with.
        $flat = json_encode(app(WebsiteOrderPayload::class)->for($order));
        expect($flat)->not->toContain('supplier')
            ->not->toContain($this->supplier->business_name)
            // Colon-prefixed so the legitimate 1300.00 selling price — which
            // contains "300.00" as a bare substring — is not a false positive.
            ->not->toContain(':1000.00')
            ->not->toContain(':300.00');

        expect(SupplierPayable::query()->count())->toBe(1);
        $payable = SupplierPayable::query()->sole();
        expect($payable->supplier_id)->toBe($this->supplier->id)
            ->and($payable->quantity)->toBe(2)
            ->and($payable->gross_amount->toDecimal())->toBe('2000.00')
            ->and($payable->status)->toBe(PayableStatus::Pending);
    });

    it('ignores a non-preferred offer from another Supplier, however much stock it holds', function () {
        $richer = supplierTestOffer(product: $this->product, supplierRate: '800.00', platformRate: '900.00');
        $richer->stock()->update(['quantity' => 500]);

        $order = supplierOrderPlace($this->selection, 2);

        expect($order->items()->sole()->supplier_offer_id)->toBe($this->offer->id);
    });

    it('refuses the line when no preferred offer is active, and never names a Supplier', function () {
        app(SuspendSupplierOffer::class)->handle($this->offer, User::factory()->create()->id, 'Fixture suspension.');

        expect(fn () => supplierOrderPlace($this->selection, 1))
            ->toThrow(function (WebsiteOrderRefused $refused) {
                expect($refused->errorCode)->toBe('insufficient_stock')
                    ->and($refused->getMessage())->not->toContain($this->supplier->business_name)
                    ->and(json_encode($refused->details))->not->toContain($this->supplier->business_name);
            });

        expect(Order::query()->count())->toBe(0);
    });

    it('refuses the line when the Supplier is not operational', function () {
        $this->supplier->forceFill(['status' => SupplierStatus::Suspended])->save();

        expect(fn () => supplierOrderPlace($this->selection, 1))
            ->toThrow(WebsiteOrderRefused::class);

        expect(Order::query()->count())->toBe(0);
    });

    it('keeps an order line\'s Supplier Rate exactly as allocated, even after the rate later changes', function () {
        $order = supplierOrderPlace($this->selection, 1);
        $originalRate = $order->items()->sole()->supplier_rate->toDecimal();

        app(SetSupplierOfferRates::class)->handle(
            $this->offer,
            User::factory()->create()->id,
            Money::fromDecimal('1500.00', Currency::BDT),
            Money::fromDecimal('1800.00', Currency::BDT),
            'Rate went up.',
        );

        expect($order->items()->sole()->supplier_rate->toDecimal())->toBe($originalRate)
            ->and($originalRate)->toBe('1000.00');
    });

    it('allocates two lines in one order to two different Suppliers, never mixing their stock', function () {
        $secondSupplier = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
        $secondProduct = websiteTestProduct();
        $secondOffer = supplierTestOffer($secondSupplier, $secondProduct, supplierRate: '500.00', platformRate: '700.00', preferred: true);
        supplierTestOfferPriceVersion($secondOffer);
        $secondSelection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $secondProduct->id,
            'status' => WebsiteProductStatus::Published,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price_minor' => 70000,
            'published_at' => now(),
        ]);

        $unit1 = $this->selection->price_minor->minorUnits;
        $unit2 = $secondSelection->price_minor->minorUnits;
        $subtotal = $unit1 + $unit2;
        $delivery = 6000;

        [$order] = app(PlaceWebsiteOrder::class)->handle($this->website, new WebsiteOrderSubmission(
            reference: 'SF-MULTI-'.Str::upper(Str::random(6)),
            idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Karim Hossain', mobile: '+8801712345000'),
            shippingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
            billingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
            items: [
                ['sku' => $this->product->sku, 'quantity' => 1],
                ['sku' => $secondProduct->sku, 'quantity' => 1],
            ],
            claimedUnitPrices: [Money::of($unit1, Currency::BDT), Money::of($unit2, Currency::BDT)],
            claimedTotals: [
                'subtotal' => Money::of($subtotal, Currency::BDT),
                'discount' => Money::of(0, Currency::BDT),
                'shipping' => Money::of($delivery, Currency::BDT),
                'tax' => Money::of(0, Currency::BDT),
                'grand_total' => Money::of($subtotal + $delivery, Currency::BDT),
            ],
            paymentMethod: 'online',
        ));

        $items = $order->refresh()->items()->orderBy('line_number')->get();

        expect($items[0]->supplier_id)->toBe($this->supplier->id)
            ->and($items[1]->supplier_id)->toBe($secondSupplier->id)
            ->and(SupplierPayable::query()->count())->toBe(2);

        expect(SupplierOfferStock::query()->where('supplier_offer_id', $this->offer->id)->sole()->reserved_quantity)->toBe(1)
            ->and(SupplierOfferStock::query()->where('supplier_offer_id', $secondOffer->id)->sole()->reserved_quantity)->toBe(1);
    });

    it('still serves a legacy central-stock line unchanged, alongside a Supplier-sourced one', function () {
        $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        $centralProduct = websiteTestProduct();
        $centralItem = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $centralProduct->id]);
        app(StockLedger::class)->move($centralItem, null, StockBucket::Available, 10, StockMovementType::Adjustment);

        $centralSelection = WebsiteProduct::create([
            'website_id' => $this->website->id,
            'business_account_id' => $this->account->id,
            'product_id' => $centralProduct->id,
            'status' => WebsiteProductStatus::Published,
            'sync_status' => WebsiteSyncStatus::Pending,
            'currency_code' => 'BDT',
            'price_minor' => 50000,
            'published_at' => now(),
        ]);

        $unit1 = $this->selection->price_minor->minorUnits;
        $unit2 = $centralSelection->price_minor->minorUnits;
        $subtotal = $unit1 + $unit2;
        $delivery = 6000;

        [$order] = app(PlaceWebsiteOrder::class)->handle($this->website, new WebsiteOrderSubmission(
            reference: 'SF-LEGACY-'.Str::upper(Str::random(6)),
            idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Karim Hossain', mobile: '+8801712345111'),
            shippingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
            billingAddress: ['line1' => 'House 4', 'city' => 'Dhaka', 'country' => 'BD'],
            items: [
                ['sku' => $this->product->sku, 'quantity' => 1],
                ['sku' => $centralProduct->sku, 'quantity' => 1],
            ],
            claimedUnitPrices: [Money::of($unit1, Currency::BDT), Money::of($unit2, Currency::BDT)],
            claimedTotals: [
                'subtotal' => Money::of($subtotal, Currency::BDT),
                'discount' => Money::of(0, Currency::BDT),
                'shipping' => Money::of($delivery, Currency::BDT),
                'tax' => Money::of(0, Currency::BDT),
                'grand_total' => Money::of($subtotal + $delivery, Currency::BDT),
            ],
            paymentMethod: 'online',
        ));

        $items = $order->refresh()->items()->orderBy('line_number')->get();

        expect($items[0]->isSupplierBacked())->toBeTrue()
            ->and($items[1]->isSupplierBacked())->toBeFalse()
            ->and($items[1]->stockReservation->isSupplierSourced())->toBeFalse()
            ->and($items[1]->stockReservation->item->id)->toBe($centralItem->id)
            ->and($centralItem->refresh()->available)->toBe(9)
            ->and(SupplierPayable::query()->count())->toBe(1);
    });
});

describe('reservation', function () {
    it('refuses when the Supplier\'s stock cannot cover the quantity', function () {
        expect(fn () => supplierOrderPlace($this->selection, 20))->toThrow(WebsiteOrderRefused::class);

        expect(Order::query()->count())->toBe(0)
            ->and(SupplierOfferStock::query()->sole()->quantity)->toBe(10);
    });

    it('commits Supplier stock to processing exactly once when payment settles', function () {
        $order = supplierOrderPlace($this->selection, 2);
        supplierOrderPay($order);
        supplierOrderPay($order); // The gateway retries; nothing moves twice.

        $stock = SupplierOfferStock::query()->sole();

        expect($order->refresh()->status)->toBe(OrderStatus::Paid)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and($stock->processing_quantity)->toBe(2)
            ->and(SupplierStockMovement::query()->where('source', 'reservation_committed')->count())->toBe(1);
    });

    it('releases Supplier stock exactly once on cancellation', function () {
        $order = supplierOrderPlace($this->selection, 2);

        $cancelled = app(CancelUnpaidOrder::class)->handle($order, UnpaidOrderCancellation::PaymentCancelled);
        $again = app(CancelUnpaidOrder::class)->handle($order->refresh(), UnpaidOrderCancellation::PaymentCancelled);

        $stock = SupplierOfferStock::query()->sole();

        expect($cancelled)->toBeTrue()
            ->and($again)->toBeFalse()
            ->and($stock->quantity)->toBe(10)
            ->and($stock->reserved_quantity)->toBe(0)
            ->and(SupplierPayable::query()->sole()->status)->toBe(PayableStatus::Cancelled);
    });

    it('follows the same cash-on-delivery confirmation rule Supplier stock does for central stock', function () {
        $order = supplierOrderPlace($this->selection, 1, 'cod');

        expect($order->status)->toBe(OrderStatus::CustomerVerificationPending)
            ->and(SupplierOfferStock::query()->sole()->reserved_quantity)->toBe(1);

        $code = app(VerificationCodes::class)->issue(SendCodConfirmationCode::PURPOSE, (string) app(SendCodConfirmationCode::class)->identifierFor($order));
        app(ConfirmCodOrder::class)->handle($order, $code);

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
            ->and(SupplierOfferStock::query()->sole()->processing_quantity)->toBe(1)
            // Confirming is not paying (contract §6.1.2): the payable stands.
            ->and(SupplierPayable::query()->sole()->status)->toBe(PayableStatus::Pending);
    });
});

describe('payable lifecycle', function () {
    beforeEach(function () {
        // Deciding and receiving a return needs order.edit and inventory.edit
        // together ({@see \App\Domain\Order\Actions\ReceiveReturnedItems}) —
        // one staff member holding both, exactly as the warehouse lead does
        // in the equivalent central-stock return tests.
        $this->manager = testPlatformStaff(PlatformRole::OrderManager);
        $this->manager->assignRole(PlatformRole::InventoryManager->value);
    });

    it('does not become eligible on payment alone, delivery alone, or in the wrong order — only once both are true', function () {
        $order = supplierOrderPlace($this->selection, 1);
        $payable = SupplierPayable::query()->sole();

        supplierOrderPay($order);
        expect($payable->refresh()->status)->toBe(PayableStatus::Pending)
            ->and($payable->payment_settled_at)->not->toBeNull()
            ->and($payable->eligible_at)->toBeNull();

        supplierOrderDeliver($order->refresh(), $this->offer);
        app(RecordSupplierPayableDelivery::class)->handle($this->manager, $order->items()->sole());

        expect($payable->refresh()->status)->toBe(PayableStatus::Eligible)
            ->and($payable->delivered_at)->not->toBeNull()
            ->and($payable->eligible_at)->not->toBeNull();

        // Recording delivery again changes nothing further.
        app(RecordSupplierPayableDelivery::class)->handle($this->manager, $order->items()->sole());
        expect($payable->refresh()->status)->toBe(PayableStatus::Eligible)
            ->and($payable->statusHistory()->count())->toBe(2); // created (Pending) → Eligible
    });

    it('does not become eligible from delivery before payment settles', function () {
        // Delivery recorded before the order is even paid — an order the
        // real fulfilment status chain would never reach yet, but the fact
        // {@see RecordSupplierPayableDelivery} records does not depend on it.
        $order = supplierOrderPlace($this->selection, 1);
        $payable = SupplierPayable::query()->sole();

        app(RecordSupplierPayableDelivery::class)->handle($this->manager, $order->items()->sole());

        expect($payable->refresh()->status)->toBe(PayableStatus::Pending)
            ->and($payable->delivered_at)->not->toBeNull();

        supplierOrderPay($order->refresh());

        expect($payable->refresh()->status)->toBe(PayableStatus::Eligible);
    });

    it('reverses the proportional Supplier amount per return, and never lets reversals exceed the payable', function () {
        $order = supplierOrderPlace($this->selection, 3);
        $payable = SupplierPayable::query()->sole();
        $sku = $order->items()->sole()->sku;
        $warehouse = Warehouse::create(['code' => 'RET', 'name' => 'Returns', 'is_default' => false]);

        supplierOrderPay($order);
        supplierOrderDeliver($order->refresh(), $this->offer);
        app(RecordSupplierPayableDelivery::class)->handle($this->manager, $order->items()->sole());

        $receiveOneUnit = function () use ($order, $sku, $warehouse) {
            [$return] = app(RequestOrderReturn::class)->handle($order->refresh(), new ReturnSubmission(
                reason: ReturnReason::NotAsDescribed,
                lines: [['sku' => $sku, 'quantity' => 1]],
            ), OrderStatusChangeSource::Storefront);

            app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'As described by the customer.');

            app(ReceiveReturnedItems::class)->handle(
                $this->manager,
                $return,
                [new ReturnedLine($return->items()->sole()->public_id, 1, ReturnDisposition::Restock)],
                $warehouse,
            );

            return $return;
        };

        $receiveOneUnit();

        expect(SupplierPayableReversal::query()->count())->toBe(1)
            ->and($payable->refresh()->status)->toBe(PayableStatus::PartiallyReversed)
            ->and($payable->reversedQuantity())->toBe(1)
            ->and($payable->netAmount()->toDecimal())->toBe('2000.00')
            // The Supplier's own stock got the unit back, not central stock:
            // 10 minus the 3 the order allocated, plus this one restock.
            ->and(SupplierOfferStock::query()->sole()->quantity)->toBe(8);

        $receiveOneUnit();
        $receiveOneUnit();

        expect($payable->refresh()->status)->toBe(PayableStatus::Reversed)
            ->and($payable->reversedQuantity())->toBe(3)
            ->and($payable->netAmount()->toDecimal())->toBe('0.00')
            ->and((int) SupplierPayableReversal::query()->sum('quantity'))->toBe(3)
            ->and((string) (SupplierPayableReversal::query()->sum('amount') ?: '0'))->toBe($payable->gross_amount->toDecimal());

        // Every sold unit has already come back; a further return is refused,
        // which is what keeps a reversal from ever exceeding the payable.
        expect(fn () => app(RequestOrderReturn::class)->handle($order->refresh(), new ReturnSubmission(
            reason: ReturnReason::NotAsDescribed,
            lines: [['sku' => $sku, 'quantity' => 1]],
        ), OrderStatusChangeSource::Storefront))->toThrow(ReturnRefused::class);
    });
});

describe('self-scope and privacy', function () {
    it('never lets one Supplier see another\'s allocation or payable', function () {
        $order = supplierOrderPlace($this->selection, 1);
        $item = $order->items()->sole();
        $payable = SupplierPayable::query()->sole();

        $stranger = Supplier::factory()->create(['status' => SupplierStatus::Approved]);
        supplierTestSignIn($stranger);

        $this->get(route('supplier.allocations.show', $item->public_id))->assertNotFound();
        $this->get(route('supplier.payables.show', $payable->public_id))->assertNotFound();

        supplierTestSignIn($this->supplier);

        $this->get(route('supplier.allocations.show', $item->public_id))->assertOk();
        $this->get(route('supplier.payables.show', $payable->public_id))->assertOk();
    });

    it('refuses supplier pricing screens to staff without supplier_pricing.view, and payable screens without supplier_payable.view', function () {
        $order = supplierOrderPlace($this->selection, 1);
        $item = $order->items()->sole();
        $payable = SupplierPayable::query()->sole();

        $staff = testPlatformStaff(PlatformRole::OrderManager);
        $this->actingAs($staff);

        $this->get(route('admin.supplier-allocations.show', $item->public_id))->assertForbidden();
        $this->get(route('admin.supplier-payables.show', $payable->public_id))->assertForbidden();

        $manager = testPlatformStaff(PlatformRole::SupplierManager);
        $this->actingAs($manager);

        $this->get(route('admin.supplier-allocations.show', $item->public_id))->assertOk();
        $this->get(route('admin.supplier-payables.show', $payable->public_id))->assertOk();
    });
});
