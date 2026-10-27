<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\ChangeReturnResponsibility;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\FinalizeReturnInspection;
use App\Domain\Order\Actions\MarkPartnerStockSold;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnParcel;
use App\Domain\Order\Actions\RecordExchangeCollection;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Actions\StartReturnInspection;
use App\Domain\Order\Actions\StartReturnSettlement;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnInitiator;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Exceptions\ReturnRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Order\Models\ReturnCharge;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exchanges (D35): one return case for the old goods, one replacement order for
 * the new, linked to each other and to the original — and never a second sale.
 *
 * The goods of one order — three panjabis at ৳2,600, ৳80 delivery — are the
 * vehicle, exactly as for a return. An exchange of two of them is asked for and
 * taken through every stage, and the figures are read back: the original ৳80
 * collected once and never again, the ৳40 return charge, the one new ৳80
 * delivery, and so ৳120 of new liability.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);
    $settings->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Ayesha Fashion']);
    websiteTestFee(FeeType::WebsiteDelivery, 8000);

    // What a delivery costs Banij to send: ৳80, for any weight, anywhere.
    DeliveryChargeRule::create([
        'weight_from_grams' => 0,
        'currency_code' => 'BDT',
        'base_charge' => Money::fromDecimal('80.00', Currency::BDT),
        'priority' => 0,
        'effective_from' => CarbonImmutable::now()->subDay(),
        'is_active' => true,
    ]);

    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00'),
        'published_at' => now(),
    ]);

    $this->stock = websiteTestOwnsSelection($this->selection, 20);

    $this->manager = testPlatformStaff(PlatformRole::OrderManager);
    $this->finance = testPlatformStaff(PlatformRole::FinanceManager);
});

function exDeliveredOrder(): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-EX-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->selection->product->sku, 'quantity' => 3]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: [
            'subtotal' => $money('7800.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('80.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('7880.00'),
        ],
        paymentMethod: 'cod',
    ));

    $code = app(VerificationCodes::class)->issue(SendCodConfirmationCode::PURPOSE, $order->public_id);
    app(ConfirmCodOrder::class)->handle($order, $code);

    $order->refresh();

    foreach ($order->items()->with('stockReservation')->get() as $line) {
        app(StockReservations::class)->commit($line->stockReservation);
    }

    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test, as fulfilment will.'), OrderStatusChangeSource::System);
    }

    app(PartnerStock::class)->move(test()->stock, StockBucket::Processing, StockBucket::Sold, 3, PartnerStockMovementType::Adjustment);

    return $order->refresh();
}

/**
 * Ask for an exchange of `$quantity` panjabis, as the Partner does from their panel.
 *
 * @param  array<string, mixed>  $options
 */
function exAsk(Order $order, int $quantity = 2, array $options = []): OrderReturn
{
    $item = $order->items()->sole();

    [$case] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
        reason: ReturnReason::NotAsDescribed,
        lines: [['sku' => $item->sku, 'quantity' => $quantity]],
        customerNote: 'The size is wrong.',
        idempotencyKey: $options['key'] ?? null,
        caseType: ReturnCaseType::Exchange,
        initiator: $options['initiator'] ?? null,
        onBehalfOf: $options['on_behalf_of'] ?? null,
        responsibleParty: $options['responsible'] ?? null,
        replacements: $options['replacements'] ?? [],
    ), $options['source'] ?? OrderStatusChangeSource::Account, $options['actor'] ?? null);

    return $case;
}

function exLine(OrderReturn $case, ReturnDisposition $disposition = ReturnDisposition::Restock): ReturnedLine
{
    $line = $case->items()->sole();

    return new ReturnedLine($line->public_id, (int) $line->approved_quantity, $disposition);
}

function exAdvance(OrderReturn $case, ReturnStatus $to): OrderReturn
{
    $staff = test()->manager;

    // What to do next from each stage, so a case already part-way is carried on, not restarted.
    $next = [
        ReturnStatus::Requested->value => fn () => app(DecideOrderReturn::class)->approve($staff, $case, [], 'The goods are as described.'),
        ReturnStatus::WaitingForParcel->value => fn () => app(ReceiveReturnParcel::class)->handle($staff, $case),
        ReturnStatus::ParcelReceived->value => fn () => app(StartReturnInspection::class)->handle($staff, $case),
        ReturnStatus::UnderInspection->value => fn () => app(FinalizeReturnInspection::class)->approve($staff, $case, [exLine($case)], test()->warehouse, 'Fit to sell.'),
    ];

    while ($case->refresh()->status !== $to && isset($next[$case->status->value])) {
        $next[$case->status->value]();
    }

    return $case->refresh();
}

/**
 * A second product the shop sells, at `$price`, with `$units` of it bought by the Partner.
 */
function exOtherProduct(string $price, int $units = 10): WebsiteProduct
{
    $selection = WebsiteProduct::create([
        'website_id' => test()->website->id,
        'business_account_id' => test()->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal($price),
        'published_at' => now(),
    ]);

    if ($units > 0) {
        test()->otherStock = websiteTestOwnsSelection($selection, $units);
    }

    return $selection;
}

describe('asking for an exchange', function () {
    it('makes one return case and one replacement order, linked to the original and to each other', function () {
        $order = exDeliveredOrder();
        $itemBefore = $order->items()->sole()->only(['sku', 'quantity', 'unit_price', 'line_total']);

        $case = exAsk($order);
        $exchange = $case->exchangeOrder;

        expect($case->case_type)->toBe(ReturnCaseType::Exchange)
            ->and($case->status)->toBe(ReturnStatus::Requested)
            ->and($case->exchange_sequence)->toBe(1)
            ->and($case->exchange_number)->toBe('EX-'.$order->reference)
            ->and($case->original_order_id)->toBe($order->id)
            ->and($case->previous_exchange_id)->toBeNull()
            ->and($exchange)->not->toBeNull()
            ->and($exchange->reference)->toBe('EX-'.$order->reference)
            ->and($exchange->source)->toBe(OrderSource::Exchange)
            ->and($exchange->original_order_id)->toBe($order->id)
            ->and($exchange->exchange_return_id)->toBe($case->id)
            ->and($exchange->status)->toBe(OrderStatus::PendingConfirmation)
            // Not a sale: no payment of its own, and worth nothing to the customer.
            ->and($exchange->payment_id)->toBeNull()
            ->and($exchange->total->toDecimal())->toBe('0.00')
            ->and(OrderReturn::query()->where('order_id', $order->id)->count())->toBe(1)
            ->and(Order::query()->where('source', OrderSource::Exchange)->count())->toBe(1);

        // The replacement line points back at the line it replaces and shares nothing with it.
        $line = $exchange->items()->sole();

        expect($line->replaces_order_item_id)->toBe($order->items()->sole()->id)
            ->and($line->quantity)->toBe(2)
            ->and($line->unit_price->toDecimal())->toBe('2600.00')
            ->and($line->defersProductCost())->toBeFalse()
            ->and($line->stock_reservation_id)->toBeNull();

        // The original order and its line are exactly as they were.
        expect($order->refresh()->items()->sole()->only(['sku', 'quantity', 'unit_price', 'line_total']))->toEqual($itemBefore)
            ->and($order->status)->toBe(OrderStatus::Delivered);
    });

    it('moves no stock and posts no money until the goods are approved', function () {
        $order = exDeliveredOrder();
        $before = $this->stock->refresh()->buckets();

        $case = exAsk($order);

        expect($this->stock->refresh()->buckets())->toBe($before)
            ->and(ReturnCharge::query()->count())->toBe(0)
            ->and($case->exchangeOrder->items()->sole()->stock_reservation_id)->toBeNull();
    });

    it('snapshots the original delivery, half of it as the return charge, and the new delivery', function () {
        $case = exAsk(exDeliveredOrder());

        expect($case->original_delivery_charge->toDecimal())->toBe('80.00')
            ->and($case->return_charge->toDecimal())->toBe('40.00')
            ->and($case->exchange_delivery_charge->toDecimal())->toBe('80.00')
            ->and($case->exchange_difference_state)->toBe('not_applicable')
            ->and($case->exchange_additional_due->toDecimal())->toBe('0.00')
            ->and($case->exchange_refund_due->toDecimal())->toBe('0.00');
    });

    it('is one case and one order however many times the same request arrives', function () {
        $order = exDeliveredOrder();

        $first = exAsk($order, options: ['key' => 'same-exchange']);
        $second = exAsk($order, options: ['key' => 'same-exchange']);

        expect($second->id)->toBe($first->id)
            ->and(OrderReturn::query()->where('order_id', $order->id)->count())->toBe(1)
            ->and(Order::query()->where('source', OrderSource::Exchange)->count())->toBe(1);
    });

    it('cannot ask for more units than are left to exchange', function () {
        $order = exDeliveredOrder();

        exAsk($order, 2);

        expect(fn () => exAsk($order, 2))->toThrow(ReturnRefused::class)
            ->and(OrderReturn::query()->count())->toBe(1)
            ->and(Order::query()->where('source', OrderSource::Exchange)->count())->toBe(1);
    });

    it('is only for an order that has been delivered', function () {
        $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
        $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

        [$order] = app(PlaceWebsiteOrder::class)->handle($this->website, new WebsiteOrderSubmission(
            reference: 'SF-EX-NOT-DELIVERED',
            idempotencyKey: (string) Str::uuid(),
            customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+8801712345678'),
            shippingAddress: $address,
            billingAddress: $address,
            items: [['sku' => $this->selection->product->sku, 'quantity' => 1]],
            claimedUnitPrices: [$money('2600.00')],
            claimedTotals: [
                'subtotal' => $money('2600.00'), 'discount' => $money('0.00'), 'shipping' => $money('80.00'),
                'tax' => $money('0.00'), 'grand_total' => $money('2680.00'),
            ],
            paymentMethod: 'cod',
        ));

        expect(fn () => exAsk($order, 1))->toThrow(ReturnRefused::class)
            ->and(Order::query()->where('source', OrderSource::Exchange)->count())->toBe(0);
    });

    it('never lets a Partner declare Banij responsible', function () {
        expect(fn () => exAsk(exDeliveredOrder(), options: ['responsible' => ReturnParty::Banij]))->toThrow(ReturnRefused::class)
            ->and(Order::query()->where('source', OrderSource::Exchange)->count())->toBe(0);
    });
});

describe('the display number', function () {
    it('is EX-ORIGINAL for the first, then -2 and -3, never reused', function () {
        $order = exDeliveredOrder();

        $first = exAsk($order, 1);
        $second = exAsk($order, 1);
        $third = exAsk($order, 1);

        expect($first->exchange_number)->toBe('EX-'.$order->reference)
            ->and($second->exchange_number)->toBe('EX-'.$order->reference.'-2')
            ->and($third->exchange_number)->toBe('EX-'.$order->reference.'-3')
            ->and([$first->exchange_sequence, $second->exchange_sequence, $third->exchange_sequence])->toBe([1, 2, 3])
            ->and($second->exchangeOrder->reference)->toBe('EX-'.$order->reference.'-2');
    });

    it('is not given again when an earlier exchange was refused', function () {
        $order = exDeliveredOrder();

        $first = exAsk($order, 1);
        app(DecideOrderReturn::class)->reject($this->manager, $first, 'The photos show ordinary wear.');

        $second = exAsk($order, 1);

        expect($second->exchange_number)->toBe('EX-'.$order->reference.'-2');
    });

    it('is unique in the database, whatever the application does', function () {
        $order = exDeliveredOrder();
        $first = exAsk($order, 1);

        $second = exAsk($order, 1);

        // A savepoint each, so one refusal does not abort the test's own transaction.
        $refused = fn (Closure $change): bool => (function () use ($change) {
            try {
                DB::transaction($change);
            } catch (QueryException) {
                return true;
            }

            return false;
        })();

        expect($refused(fn () => DB::table('order_returns')->where('id', $first->id)->update(['exchange_number' => 'EX-CHANGED'])))->toBeTrue()
            // Two cases cannot share a place in an original order's sequence.
            ->and($refused(fn () => DB::table('order_returns')->where('id', $second->id)->update(['exchange_sequence' => 1])))->toBeTrue()
            // Nor can two orders share a reference.
            ->and($refused(fn () => DB::table('orders')->where('id', $second->exchange_order_id)->update(['reference' => $first->exchange_number])))->toBeTrue();
    });

    it('continues the original\'s sequence when the replacement is itself exchanged, and remembers the one before', function () {
        $order = exDeliveredOrder();
        $first = exAsk($order, 2);

        // Take the first exchange all the way through, then deliver its replacement.
        exAdvance($first, ReturnStatus::InspectionApproved);
        $replacement = $first->refresh()->exchangeOrder->refresh();

        foreach ([
            OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
            OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
        ] as $status) {
            $replacement->moveTo($status, new StatusChange(reason: 'Moved by the test.'), OrderStatusChangeSource::System);
        }

        $second = exAsk($replacement->refresh(), 1);

        expect($second->exchange_number)->toBe('EX-'.$order->reference.'-2')
            ->and($second->original_order_id)->toBe($order->id)
            ->and($second->order_id)->toBe($replacement->id)
            ->and($second->previous_exchange_id)->toBe($first->id)
            ->and($second->exchangeOrder->original_order_id)->toBe($order->id);
    });
});

describe('releasing the replacement', function () {
    it('reserves and commits the replacement only once the returned goods are approved', function () {
        $case = exAsk(exDeliveredOrder());
        $replacement = $case->exchangeOrder;

        exAdvance($case, ReturnStatus::UnderInspection);

        expect($replacement->refresh()->status)->toBe(OrderStatus::PendingConfirmation)
            ->and($replacement->items()->sole()->stock_reservation_id)->toBeNull();

        $available = $this->stock->refresh()->available;

        exAdvance($case, ReturnStatus::InspectionApproved);

        // The two returned units went back on the shelf, and two were taken for the replacement.
        expect($replacement->refresh()->status)->toBe(OrderStatus::Confirmed)
            ->and($replacement->items()->sole()->stock_reservation_id)->not->toBeNull()
            ->and($this->stock->refresh()->available)->toBe($available)
            ->and($this->stock->processing)->toBe(2);
    });

    it('consumes the replacement\'s units when it is delivered, exactly once', function () {
        $case = exAsk(exDeliveredOrder());
        exAdvance($case, ReturnStatus::InspectionApproved);
        $replacement = $case->refresh()->exchangeOrder->refresh();

        app(MarkPartnerStockSold::class)->handle($replacement);
        app(MarkPartnerStockSold::class)->handle($replacement);

        expect($this->stock->refresh()->sold)->toBe(3)
            ->and($this->stock->processing)->toBe(0);
    });

    it('refuses to approve the goods over a replacement nobody can send, and changes nothing', function () {
        $other = exOtherProduct('2600.00', units: 10);
        $otherStock = test()->otherStock;
        $case = exAsk(exDeliveredOrder(), options: ['replacements' => [test()->selection->product->sku => $other->product->sku]]);

        exAdvance($case, ReturnStatus::UnderInspection);

        // Someone else takes every unit of it while the returned goods are being looked at.
        app(PartnerStock::class)->move($otherStock, StockBucket::Available, StockBucket::Sold, 10, PartnerStockMovementType::Adjustment);
        $sold = $this->stock->refresh()->sold;

        expect(fn () => app(FinalizeReturnInspection::class)->approve($this->manager, $case, [exLine($case)], $this->warehouse))
            ->toThrow(ReturnRefused::class);

        expect($case->refresh()->status)->toBe(ReturnStatus::UnderInspection)
            ->and($this->stock->refresh()->sold)->toBe($sold)
            ->and($case->exchangeOrder->refresh()->status)->toBe(OrderStatus::PendingConfirmation);
    });

    it('cancels the replacement when the request is refused', function () {
        $case = exAsk(exDeliveredOrder());

        app(DecideOrderReturn::class)->reject($this->manager, $case, 'The photos show ordinary wear.');

        expect($case->exchangeOrder->refresh()->status)->toBe(OrderStatus::Cancelled);
    });

    it('cancels the replacement, and sends nothing, when the goods fail inspection', function () {
        $case = exAsk(exDeliveredOrder());
        exAdvance($case, ReturnStatus::UnderInspection);

        app(FinalizeReturnInspection::class)->reject($this->manager, $case, 'The items were worn and washed.');

        expect($case->refresh()->status)->toBe(ReturnStatus::InspectionRejected)
            ->and($case->exchangeOrder->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($this->stock->refresh()->processing)->toBe(0);
    });
});

describe('what an exchange costs', function () {
    it('charges Banij the return charge and the new delivery, once each, when Banij is responsible', function () {
        $wallet = websiteTestWallet($this->account, '500.00');
        $case = exAsk(exDeliveredOrder(), options: [
            'source' => OrderStatusChangeSource::Staff, 'initiator' => ReturnInitiator::Staff,
            'responsible' => ReturnParty::Banij, 'actor' => $this->manager,
        ]);

        exAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $return = ReturnCharge::query()->where('order_return_id', $case->id)->where('kind', 'return_charge')->sole();
        $delivery = ReturnCharge::query()->where('order_return_id', $case->id)->where('kind', 'exchange_delivery')->sole();

        expect($return->bearer)->toBe(ReturnParty::Banij)
            ->and($return->status)->toBe(ReturnChargeStatus::Recorded)
            ->and($return->amount->toDecimal())->toBe('40.00')
            ->and($delivery->bearer)->toBe(ReturnParty::Banij)
            ->and($delivery->status)->toBe(ReturnChargeStatus::Recorded)
            ->and($delivery->amount->toDecimal())->toBe('80.00')
            ->and($wallet->refresh()->total->toDecimal())->toBe('500.00');
    });

    it('charges the Partner ৳120, not ৳200: the original ৳80 is not charged again', function () {
        $wallet = websiteTestWallet($this->account, '500.00');
        $case = exAsk(exDeliveredOrder());

        exAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $charges = ReturnCharge::query()->where('order_return_id', $case->id)->get();

        expect($charges)->toHaveCount(2)
            ->and($charges->every(fn (ReturnCharge $charge) => $charge->bearer === ReturnParty::Partner && $charge->status === ReturnChargeStatus::Posted))->toBeTrue()
            ->and($charges->sum(fn (ReturnCharge $charge) => (float) $charge->amount->toDecimal()))->toBe(120.0)
            ->and($wallet->refresh()->total->toDecimal())->toBe('380.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::ReturnChargeDebit->value)->count())->toBe(1)
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::ExchangeDeliveryDebit->value)->count())->toBe(1);
    });

    it('posts each charge once however many times settlement is asked for', function () {
        websiteTestWallet($this->account, '500.00');
        $case = exAsk(exDeliveredOrder());
        exAdvance($case, ReturnStatus::InspectionApproved);

        app(StartReturnSettlement::class)->handle($this->finance, $case);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        expect($case->charges()->count())->toBe(2)
            ->and(LedgerEntry::query()->whereIn('type', [LedgerTransactionType::ReturnChargeDebit->value, LedgerTransactionType::ExchangeDeliveryDebit->value])->count())->toBe(2);
    });

    it('moves both charges to the new party, as one compensating pair each, when responsibility changes', function () {
        $wallet = websiteTestWallet($this->account, '500.00');
        $case = exAsk(exDeliveredOrder());
        exAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        app(ChangeReturnResponsibility::class)->handle($this->manager, $case, ReturnParty::Banij, 'Sent the wrong size: the fault is ours.');

        expect($wallet->refresh()->total->toDecimal())->toBe('500.00')
            ->and($case->charges()->where('entry_type', 'reversal')->count())->toBe(2)
            ->and($case->charges()->where('entry_type', 'charge')->where('bearer', 'banij')->count())->toBe(2)
            ->and($case->charges()->count())->toBe(6);
    });

    it('does not create a second sale: nothing defers, no payable, no payment', function () {
        $case = exAsk(exDeliveredOrder());
        exAdvance($case, ReturnStatus::InspectionApproved);

        $replacement = $case->refresh()->exchangeOrder;

        expect($replacement->payment_id)->toBeNull()
            ->and($replacement->items()->get()->every(fn ($line) => ! $line->defersProductCost() && $line->supplier_offer_id === null))->toBeTrue()
            ->and(DB::table('order_proceeds_settlements')->count())->toBe(0);
    });
});

describe('a replacement that is not the same product', function () {
    it('is priced by the server: a dearer one leaves an amount to collect, shown before and recorded once', function () {
        $dearer = exOtherProduct('3000.00');
        $case = exAsk(exDeliveredOrder(), options: ['replacements' => [test()->selection->product->sku => $dearer->product->sku]]);

        // 2 × ৳3,000 against 2 × ৳2,600 of goods coming back.
        expect($case->exchange_additional_due->toDecimal())->toBe('800.00')
            ->and($case->exchange_refund_due->toDecimal())->toBe('0.00')
            ->and($case->exchange_difference_state)->toBe('collection_pending');

        $line = $case->exchangeOrder->items()->sole();

        expect($line->product_id)->toBe($dearer->product_id)
            ->and($line->unit_price->toDecimal())->toBe('3000.00')
            ->and($line->discount->toDecimal())->toBe('5200.00')
            ->and($line->line_total->toDecimal())->toBe('800.00');
    });

    it('is a refund of the difference when it is cheaper, through the existing refund machinery', function () {
        $cheaper = exOtherProduct('2000.00');
        $case = exAsk(exDeliveredOrder(), options: ['replacements' => [test()->selection->product->sku => $cheaper->product->sku]]);

        // 2 × ৳2,000 against 2 × ৳2,600 coming back: ৳1,200 owed to the customer.
        expect($case->exchange_refund_due->toDecimal())->toBe('1200.00')
            ->and($case->exchange_additional_due->toDecimal())->toBe('0.00')
            ->and($case->exchange_difference_state)->toBe('refund_due');

        exAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        // Cash on delivery: no gateway can send it, so it waits for a person — for exactly the difference.
        expect($case->refresh()->refund_amount->toDecimal())->toBe('1200.00')
            ->and($case->refund_state)->toBe(ReturnRefundState::ManualReview)
            ->and($case->items()->sole()->refund_amount)->toBeNull();
    });

    it('refuses a product the shop does not sell', function () {
        $unlisted = exOtherProduct('2000.00', units: 0);
        $unlisted->forceFill(['status' => WebsiteProductStatus::Unpublished])->save();

        expect(fn () => exAsk(exDeliveredOrder(), options: ['replacements' => [test()->selection->product->sku => $unlisted->product->sku]]))
            ->toThrow(ReturnRefused::class)
            ->and(fn () => exAsk(exDeliveredOrder(), options: ['replacements' => [test()->selection->product->sku => 'NOT-A-SKU']]))
            ->toThrow(ReturnRefused::class);
    });

    it('records the collection of a dearer replacement once, with the reason, and only for finance', function () {
        $dearer = exOtherProduct('3000.00');
        $case = exAsk(exDeliveredOrder(), options: ['replacements' => [test()->selection->product->sku => $dearer->product->sku]]);

        expect(fn () => app(RecordExchangeCollection::class)->handle($this->manager, $case, 'Collected on delivery, receipt 4471.'))
            ->toThrow(AuthorizationException::class);

        app(RecordExchangeCollection::class)->handle($this->finance, $case, 'Collected on delivery, receipt 4471.');
        app(RecordExchangeCollection::class)->handle($this->finance, $case, 'Collected on delivery, receipt 4471.');

        expect($case->refresh()->exchange_difference_state)->toBe('collected')
            ->and(fn () => app(RecordExchangeCollection::class)->handle($this->finance, exAsk(exDeliveredOrder()), 'Collected on delivery.'))
            ->toThrow(ReturnRefused::class);
    });
});

describe('who sees an exchange', function () {
    it('shows a Partner their own and no other Partner\'s exchange orders', function () {
        $case = exAsk(exDeliveredOrder());
        $other = websiteTestAccount();

        expect($this->account->owner->can('view', $case->exchangeOrder))->toBeTrue()
            ->and($other->owner->can('view', $case->exchangeOrder))->toBeFalse()
            ->and($other->owner->can('view', $case))->toBeFalse();
    });

    it('is staff\'s to open on a Partner\'s behalf with the exchange.create right', function () {
        $outsider = testPlatformStaff(PlatformRole::FinanceManager);

        expect(fn () => exAsk(exDeliveredOrder(), options: [
            'source' => OrderStatusChangeSource::Staff, 'initiator' => ReturnInitiator::Staff, 'actor' => $outsider,
        ]))->toThrow(AuthorizationException::class);

        $case = exAsk(exDeliveredOrder(), options: [
            'source' => OrderStatusChangeSource::Staff, 'initiator' => ReturnInitiator::Staff, 'responsible' => ReturnParty::Banij, 'actor' => $this->manager,
        ]);

        expect($case->initiated_by_type)->toBe(ReturnInitiator::Staff)
            ->and($case->responsible_party)->toBe(ReturnParty::Banij);
    });
});
