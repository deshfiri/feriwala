<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Access\Exceptions\SensitiveActionRefused;
use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\AllocationType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\CancelOrderReturn;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\RefundOrderReturn;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Actions\SettleReturnRefundManually;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\ReturnTerms;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Goods coming back, and the money that follows them (§18.2, §19.1, §26.3,
 * contract §6.3, P6-12).
 *
 * Nothing in the application delivers an order yet — that is fulfilment
 * (§20, P6-17), which is a later phase — so these tests move an order to
 * delivered by hand, through the status map, and record its units as sold the
 * way delivery will. Everything after that is the returns flow as it runs.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    $settings->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    Http::fake(fn (ClientRequest $request) => Http::response(
        str_contains($request->url(), 'gwprocess')
            ? ['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']
            : ['status' => 'VALID', 'currency_amount' => '7860.00', 'currency_type' => 'BDT'],
    ));

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Ayesha Fashion']);
    websiteTestFee(FeeType::WebsiteDelivery, 6000);

    app(ManageWebhookEndpoint::class)
        ->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');

    // 2,600 taka a panjabi; three of them.
    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 260000,
        'published_at' => now(),
    ]);

    $this->stock = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->selection->product_id]);
    app(StockLedger::class)->move($this->stock, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    $this->manager = testPlatformStaff(PlatformRole::OrderManager);

    // Somebody who may both move a return along and put goods back in stock.
    $this->warehouseLead = testPlatformStaff(PlatformRole::OrderManager);
    $this->warehouseLead->assignRole(PlatformRole::InventoryManager->value);
});

/**
 * A website order for three panjabis, paid online or on delivery, and then
 * delivered — the part fulfilment will do once it exists.
 */
function returnTestDeliveredOrder(string $method = 'online'): Order
{
    $money = fn (int $minor) => Money::of($minor, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-RET-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->selection->product->sku, 'quantity' => 3]],
        claimedUnitPrices: [$money(260000)],
        claimedTotals: [
            'subtotal' => $money(780000),
            'discount' => $money(0),
            'shipping' => $money(6000),
            'tax' => $money(0),
            'grand_total' => $money(786000),
        ],
        paymentMethod: $method,
    ));

    if ($method === 'online') {
        test()->post(route('webhooks.payment', 'sslcommerz'), returnTestIpn((string) $order->payment?->reference));
    } else {
        $code = app(VerificationCodes::class)->issue(SendCodConfirmationCode::PURPOSE, $order->public_id);
        app(ConfirmCodOrder::class)->handle($order, $code);
    }

    $order->refresh();

    // What fulfilment will do: take it through the warehouse and hand it over.
    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test, as fulfilment will.'), OrderStatusChangeSource::System);
    }

    app(StockLedger::class)->move(test()->stock, StockBucket::Processing, StockBucket::Sold, 3, StockMovementType::Adjustment);

    return $order->refresh();
}

/**
 * @return array<string, string>
 */
function returnTestIpn(string $reference): array
{
    $fields = ['tran_id' => $reference, 'val_id' => 'val-1', 'status' => 'VALID'];
    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed))),
    ];
}

function returnTestAsk(Order $order, int $quantity = 2, ?string $key = null): OrderReturn
{
    [$return] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
        reason: ReturnReason::NotAsDescribed,
        lines: [['sku' => $order->items()->sole()->sku, 'quantity' => $quantity]],
        customerNote: 'The colour is not what the picture showed.',
        idempotencyKey: $key,
    ), OrderStatusChangeSource::Storefront);

    return $return;
}

function returnTestLine(OrderReturn $return): string
{
    return $return->items()->sole()->public_id;
}

describe('asking for a return', function () {
    it('takes the request and moves nothing', function () {
        $order = returnTestDeliveredOrder();
        $before = $this->stock->refresh()->buckets();

        $return = returnTestAsk($order);

        expect($return->status)->toBe(ReturnStatus::Requested)
            ->and($return->reference)->toStartWith('RET-')
            ->and($return->website_id)->toBe($this->website->id)
            ->and($return->items()->sole()->quantity)->toBe(2)
            ->and($return->refund_state)->toBe(ReturnRefundState::NotRequired)
            // A request is only a request (contract §6.3).
            ->and($this->stock->refresh()->buckets())->toBe($before)
            ->and(RefundRequest::query()->count())->toBe(0)
            ->and($return->statusHistory()->sole()->new_status)->toBe(ReturnStatus::Requested);

        expect(WebhookDelivery::query()->where('event_type', WebhookEvent::ReturnStatusChanged->value)->count())->toBe(1);
    });

    it('refuses an order nothing has been delivered from', function () {
        [$order] = app(PlaceWebsiteOrder::class)->handle($this->website, new WebsiteOrderSubmission(
            reference: 'SF-NOT-DELIVERED',
            idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+8801712345678'),
            shippingAddress: ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'],
            billingAddress: ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'],
            items: [['sku' => $this->selection->product->sku, 'quantity' => 1]],
            claimedUnitPrices: [Money::of(260000, Currency::BDT)],
            claimedTotals: [
                'subtotal' => Money::of(260000, Currency::BDT),
                'discount' => Money::of(0, Currency::BDT),
                'shipping' => Money::of(6000, Currency::BDT),
                'tax' => Money::of(0, Currency::BDT),
                'grand_total' => Money::of(266000, Currency::BDT),
            ],
            paymentMethod: 'cod',
        ));

        expect(fn () => returnTestAsk($order, 1))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('order_not_returnable'));

        expect(OrderReturn::query()->count())->toBe(0);
    });

    it('refuses more than was sold, a line the order never had, and a closed window', function () {
        $order = returnTestDeliveredOrder();

        expect(fn () => returnTestAsk($order, 4))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('quantity_not_returnable'));

        expect(fn () => app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
            reason: ReturnReason::WrongItem,
            lines: [['sku' => 'FW-NOT-ON-ORDER', 'quantity' => 1]],
        ), OrderStatusChangeSource::Storefront))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('line_not_on_order'));

        $this->travel(ReturnTerms::DEFAULT_WINDOW_DAYS + 1)->days();

        expect(fn () => returnTestAsk($order, 1))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('return_window_closed'));

        expect(OrderReturn::query()->count())->toBe(0);
    });

    it('answers the same request twice with one return', function () {
        $order = returnTestDeliveredOrder();

        $first = returnTestAsk($order, 2, 'return-key-1');
        $second = returnTestAsk($order, 2, 'return-key-1');

        expect($second->id)->toBe($first->id)
            ->and(OrderReturn::query()->count())->toBe(1)
            ->and($first->statusHistory()->count())->toBe(1);
    });

    it('never lets two returns together claim more than was sold', function () {
        $order = returnTestDeliveredOrder();

        returnTestAsk($order, 2);

        expect(fn () => returnTestAsk($order, 2))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('quantity_not_returnable'));

        // And the database says the same to a write that went round the action.
        $second = OrderReturn::create([
            'order_id' => $order->id,
            'business_account_id' => $order->business_account_id,
            'website_id' => $order->website_id,
            'source' => OrderStatusChangeSource::Staff,
            'status' => ReturnStatus::Requested,
            'reason' => ReturnReason::Other,
            'requested_at' => now(),
        ]);

        expect(fn () => $second->items()->create(['order_item_id' => $order->items()->sole()->id, 'quantity' => 2]))
            ->toThrow(QueryException::class, 'more of this line would come back than was sold');
    });

    it('refuses a return written against another partner\'s order', function () {
        $order = returnTestDeliveredOrder();
        $other = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);

        expect(fn () => OrderReturn::create([
            'order_id' => $order->id,
            'business_account_id' => $other->id,
            'website_id' => $order->website_id,
            'source' => OrderStatusChangeSource::Staff,
            'status' => ReturnStatus::Requested,
            'reason' => ReturnReason::Other,
            'requested_at' => now(),
        ]))->toThrow(QueryException::class, 'a return belongs to the business account of the order it is for');
    });

    it('frees its claim when it is withdrawn', function () {
        $order = returnTestDeliveredOrder();
        $return = returnTestAsk($order, 3);

        expect(app(CancelOrderReturn::class)->handle($return, OrderStatusChangeSource::Storefront))->toBeTrue()
            ->and(app(CancelOrderReturn::class)->handle($return, OrderStatusChangeSource::Storefront))->toBeFalse()
            ->and($return->refresh()->status)->toBe(ReturnStatus::Cancelled);

        // The same three may be asked for again.
        expect(returnTestAsk($order, 3)->status)->toBe(ReturnStatus::Requested);
    });
});

describe('deciding it', function () {
    it('approves part of it, with a reason, and audits the decision', function () {
        $return = returnTestAsk(returnTestDeliveredOrder(), 2);

        app(DecideOrderReturn::class)->approve($this->manager, $return, [returnTestLine($return) => 1], 'One shirt matches the photo; one does not.');

        $return->refresh();

        expect($return->status)->toBe(ReturnStatus::Approved)
            ->and($return->decided_by)->toBe($this->manager->id)
            ->and($return->items()->sole()->approved_quantity)->toBe(1)
            ->and(DB::table('audit_logs')->where('action', 'order_return.approved')->count())->toBe(1);
    });

    it('refuses a decision without a reason, and a decision taken twice', function () {
        $return = returnTestAsk(returnTestDeliveredOrder(), 2);

        expect(fn () => app(DecideOrderReturn::class)->reject($this->manager, $return, 'no'))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('decision_needs_a_reason'));

        app(DecideOrderReturn::class)->reject($this->manager, $return, 'Worn and washed; not returnable.');

        expect($return->refresh()->status)->toBe(ReturnStatus::Rejected)
            ->and(fn () => app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'Changed our minds after all.'))
            ->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('return_not_in_that_state'));
    });

    it('refuses staff who may not decide returns', function () {
        $return = returnTestAsk(returnTestDeliveredOrder(), 2);

        expect(fn () => app(DecideOrderReturn::class)->approve(testPlatformStaff(PlatformRole::InventoryManager), $return, [], 'Approving without the right to.'))
            ->toThrow(AuthorizationException::class);

        expect($return->refresh()->status)->toBe(ReturnStatus::Requested);
    });
});

describe('receiving the goods', function () {
    beforeEach(function () {
        $this->order = returnTestDeliveredOrder();
        $this->return = returnTestAsk($this->order, 2);
        app(DecideOrderReturn::class)->approve($this->manager, $this->return, [], 'Both shirts are as the customer describes.');
    });

    it('puts each unit where the inspector said, out of sold stock, once', function () {
        app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $this->return, [
            new ReturnedLine(returnTestLine($this->return), 2, ReturnDisposition::Restock),
        ], $this->warehouse);

        $stock = $this->stock->refresh();
        $line = $this->return->items()->sole();

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Received)
            ->and($line->received_quantity)->toBe(2)
            ->and($line->disposition)->toBe(ReturnDisposition::Restock)
            ->and($line->stock_movement_id)->not->toBeNull()
            ->and($stock->sold)->toBe(1)
            ->and($stock->available)->toBe(19);

        $movement = StockMovement::query()->findOrFail($line->stock_movement_id);

        expect($movement->from_bucket)->toBe(StockBucket::Sold)
            ->and($movement->to_bucket)->toBe(StockBucket::Available)
            ->and($movement->actor_id)->toBe($this->warehouseLead->id);

        // Received twice: refused, and nothing moves again.
        expect(fn () => app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $this->return, [
            new ReturnedLine(returnTestLine($this->return), 2, ReturnDisposition::Restock),
        ], $this->warehouse))->toThrow(ReturnRefused::class);

        expect($this->stock->refresh()->available)->toBe(19);
    });

    it('keeps damaged goods off sale and quarantined goods apart', function () {
        app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $this->return, [
            new ReturnedLine(returnTestLine($this->return), 2, ReturnDisposition::Damaged),
        ], $this->warehouse);

        expect($this->stock->refresh()->damaged)->toBe(2)
            ->and($this->stock->available)->toBe(17);
    });

    it('refuses more than was approved', function () {
        expect(fn () => app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $this->return, [
            new ReturnedLine(returnTestLine($this->return), 3, ReturnDisposition::Restock),
        ], $this->warehouse))->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('more_than_approved'));

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Approved);
    });

    it('needs the right to change stock as well as to move the return', function () {
        expect(fn () => app(ReceiveReturnedItems::class)->handle($this->manager, $this->return, [
            new ReturnedLine(returnTestLine($this->return), 2, ReturnDisposition::Restock),
        ], $this->warehouse))->toThrow(AuthorizationException::class);

        expect($this->stock->refresh()->sold)->toBe(3);
    });

    it('says so when the units were never recorded as sold', function () {
        // Undo the delivery's record, as if fulfilment had never written it.
        app(StockLedger::class)->move($this->stock, StockBucket::Sold, StockBucket::Processing, 3, StockMovementType::Adjustment);

        expect(fn () => app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $this->return, [
            new ReturnedLine(returnTestLine($this->return), 2, ReturnDisposition::Restock),
        ], $this->warehouse))->toThrow(fn (ReturnRefused $refused) => expect($refused->errorCode)->toBe('stock_not_recorded_as_sold'));

        expect($this->return->refresh()->status)->toBe(ReturnStatus::Approved);
    });
});

describe('the money', function () {
    it('opens a refund for what arrived, and follows it to completion', function () {
        $order = returnTestDeliveredOrder();
        $return = returnTestAsk($order, 2);
        app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'Both shirts are as the customer describes.');
        app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $return, [
            new ReturnedLine(returnTestLine($return), 2, ReturnDisposition::Restock),
        ], $this->warehouse);

        app(RefundOrderReturn::class)->handle($this->manager, $return);
        $again = app(RefundOrderReturn::class)->handle($this->manager, $return);

        $refund = RefundRequest::query()->sole();

        // Two of three at 2,600 taka each; delivery is not part of it.
        expect($return->refresh()->refund_state)->toBe(ReturnRefundState::Pending)
            ->and($again->refund_request_id)->toBe($refund->id)
            ->and($refund->status)->toBe(RefundStatus::Requested)
            ->and($refund->allocation_type)->toBe(AllocationType::WebsiteGoods)
            ->and($refund->amount_minor->minorUnits)->toBe(520000)
            ->and($return->refund_amount_minor?->minorUnits)->toBe(520000)
            // Started once, said once: the repeat changed nothing.
            ->and(DB::table('audit_logs')->where('action', 'order_return.refund_started')->count())->toBe(1);

        // The refund machinery decides and sends it; the return follows.
        $refund->transitionTo(RefundStatus::Approved)->save();
        $refund->transitionTo(RefundStatus::Failed);
        $refund->forceFill(['failure_reason' => 'The gateway was down.'])->save();

        expect($return->refresh()->refund_state)->toBe(ReturnRefundState::Failed)
            ->and($return->status)->toBe(ReturnStatus::Received);

        $refund->transitionTo(RefundStatus::Processed);
        $refund->forceFill(['processed_at' => now()])->save();

        expect($return->refresh()->refund_state)->toBe(ReturnRefundState::Completed)
            ->and($return->status)->toBe(ReturnStatus::Refunded)
            ->and(WebhookDelivery::query()->where('event_type', WebhookEvent::RefundCompleted->value)->count())->toBe(1);
    });

    it('never loses a poisha when a line comes back in pieces', function () {
        // A poisha of discount makes three units cost 7,799.99 taka, which no
        // unit price divides into — the case rounding has to get right.
        $order = returnTestDeliveredOrder();
        DB::statement('ALTER TABLE order_items DISABLE TRIGGER USER');
        DB::table('order_items')->where('order_id', $order->id)->update(['discount_minor' => 1, 'line_total_minor' => 779999]);
        DB::statement('ALTER TABLE order_items ENABLE TRIGGER USER');

        $amounts = [];

        foreach ([1, 2] as $quantity) {
            $return = returnTestAsk($order->refresh(), $quantity);
            app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'As the customer describes, every one.');
            app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $return, [
                new ReturnedLine(returnTestLine($return), $quantity, ReturnDisposition::Restock),
            ], $this->warehouse);
            app(RefundOrderReturn::class)->handle($this->manager, $return);

            $amounts[] = $return->refresh()->refund_amount_minor?->minorUnits;

            // Settle it so the next one is not blocked by an open request.
            $refund = $return->refundRequest;
            $refund?->transitionTo(RefundStatus::Approved)->save();
            $refund?->transitionTo(RefundStatus::Processed)->save();
        }

        // The first return takes the rounding down; the line still totals exactly.
        expect($amounts)->toBe([259999, 520000])
            ->and(array_sum($amounts))->toBe(779999);
    });

    it('marks a cash-on-delivery refund for a person, and never opens a gateway refund', function () {
        $order = returnTestDeliveredOrder('cod');
        $return = returnTestAsk($order, 1);
        app(DecideOrderReturn::class)->approve($this->manager, $return, [], 'The stitching is undone on the sleeve.');
        app(ReceiveReturnedItems::class)->handle($this->warehouseLead, $return, [
            new ReturnedLine(returnTestLine($return), 1, ReturnDisposition::Damaged),
        ], $this->warehouse);

        app(RefundOrderReturn::class)->handle($this->manager, $return);

        expect($return->refresh()->refund_state)->toBe(ReturnRefundState::ManualReview)
            ->and($return->refund_amount_minor?->minorUnits)->toBe(260000)
            ->and($return->refund_note)->toContain('Paid on delivery')
            ->and(RefundRequest::query()->count())->toBe(0);

        $payments = testPlatformStaff(PlatformRole::PaymentManager);

        // "I refunded this" is exactly the claim somebody would make falsely.
        expect(fn () => app(SettleReturnRefundManually::class)->handle($payments, $return, 'Paid back in cash at the door.', false, true))
            ->toThrow(SensitiveActionRefused::class);

        app(SettleReturnRefundManually::class)->handle($payments, $return, 'Paid back in cash at the door, receipt 0042.', true, true);
        app(SettleReturnRefundManually::class)->handle($payments, $return, 'Paid back in cash at the door, receipt 0042.', true, true);

        expect($return->refresh()->refund_state)->toBe(ReturnRefundState::Completed)
            ->and($return->status)->toBe(ReturnStatus::Refunded)
            ->and(DB::table('audit_logs')->where('action', 'order_return.refund_settled_manually')->count())->toBe(1);
    });
});
