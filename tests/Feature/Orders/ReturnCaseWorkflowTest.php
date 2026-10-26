<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\ChangeReturnResponsibility;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\FinalizeReturnInspection;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\ReceiveReturnParcel;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Actions\SettleOutstandingReturnCharge;
use App\Domain\Order\Actions\SettleReturnRefundManually;
use App\Domain\Order\Actions\StartReturnInspection;
use App\Domain\Order\Actions\StartReturnSettlement;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnChargeKind;
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
use App\Domain\Order\Policies\ReturnCasePolicy;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Wallet\WalletService;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StateMachine\Exceptions\IllegalStateTransition;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The return case workflow (D35): who asked and who is responsible, the strict
 * stage-by-stage progression, and the money each case owes.
 *
 * The goods of one order — three panjabis at ৳2,600, ৳60 delivery — are the
 * vehicle. Returns of two of them are asked for, taken through every stage,
 * and the figures read back: the half-delivery return charge of ৳30, a goods
 * refund of ৳5,200, and what each party is and is not charged.
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
    websiteTestFee(FeeType::WebsiteDelivery, 6000);

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

    // Decides cases, receives parcels, inspects goods — and holds no money rights.
    $this->manager = testPlatformStaff(PlatformRole::OrderManager);

    // Posts the money of a case.
    $this->finance = testPlatformStaff(PlatformRole::FinanceManager);

    // Records a refund settled by hand: the existing, password-guarded reversal right.
    $this->payments = testPlatformStaff(PlatformRole::PaymentManager);
});

/**
 * A cash-on-delivery order for three panjabis, delivered the way fulfilment
 * will deliver it, with its units recorded as sold.
 */
function rcwDeliveredOrder(): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-RCW-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->selection->product->sku, 'quantity' => 3]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: [
            'subtotal' => $money('7800.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('60.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('7860.00'),
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
 * Ask for the return of `$quantity` panjabis, as the Partner does from their panel.
 *
 * @param  array<string, mixed>  $parties
 */
function rcwOpen(Order $order, int $quantity = 2, ?string $key = null, array $parties = []): OrderReturn
{
    [$case] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
        reason: ReturnReason::NotAsDescribed,
        lines: [['sku' => $order->items()->sole()->sku, 'quantity' => $quantity]],
        customerNote: 'The colour is not what the picture showed.',
        idempotencyKey: $key,
        initiator: $parties['initiator'] ?? null,
        onBehalfOf: $parties['on_behalf_of'] ?? null,
        responsibleParty: $parties['responsible'] ?? null,
    ), $parties['source'] ?? OrderStatusChangeSource::Account, $parties['actor'] ?? null);

    return $case;
}

function rcwLine(OrderReturn $case, ReturnDisposition $disposition = ReturnDisposition::Restock, ?int $quantity = null): ReturnedLine
{
    $line = $case->items()->sole();

    return new ReturnedLine($line->public_id, $quantity ?? (int) $line->approved_quantity, $disposition);
}

/**
 * Take a case to the stage `$to`, deciding, receiving, inspecting and approving
 * as the staff who hold each right.
 */
function rcwAdvance(OrderReturn $case, ReturnStatus $to): OrderReturn
{
    $staff = test()->manager;
    $steps = [
        ReturnStatus::Approved->value => fn () => app(DecideOrderReturn::class)->approve($staff, $case, [], 'The goods are as described.'),
        ReturnStatus::ParcelReceived->value => fn () => app(ReceiveReturnParcel::class)->handle($staff, $case),
        ReturnStatus::UnderInspection->value => fn () => app(StartReturnInspection::class)->handle($staff, $case),
        ReturnStatus::InspectionApproved->value => fn () => app(FinalizeReturnInspection::class)->approve($staff, $case, [rcwLine($case)], test()->warehouse, 'Fit to sell.'),
    ];

    foreach ($steps as $stage => $step) {
        $step();

        $reached = $case->refresh()->status;

        // Approving a case carries it on to "waiting for the parcel".
        if ($reached === $to || ($to === ReturnStatus::WaitingForParcel && $stage === ReturnStatus::Approved->value)) {
            break;
        }
    }

    return $case->refresh();
}

function rcwCharge(OrderReturn $case, ReturnChargeKind $kind = ReturnChargeKind::ReturnCharge): ?ReturnCharge
{
    return $case->charges()->where('kind', $kind->value)->where('entry_type', 'charge')->orderBy('id')->first();
}

describe('opening a case', function () {
    it('is the Partner\'s own, attributed to the Partner, with the cost snapshotted and nothing moved', function () {
        $order = rcwDeliveredOrder();
        $before = $this->stock->refresh()->buckets();

        $case = rcwOpen($order);

        expect($case->status)->toBe(ReturnStatus::Requested)
            ->and($case->usesCaseWorkflow())->toBeTrue()
            ->and($case->initiated_by_type)->toBe(ReturnInitiator::Partner)
            ->and($case->on_behalf_of)->toBe(ReturnParty::Partner)
            ->and($case->responsible_party)->toBe(ReturnParty::Partner)
            // What the original delivery cost, and half of it, fixed from the order's locked figures.
            ->and($case->original_delivery_charge->toDecimal())->toBe('60.00')
            ->and($case->return_charge->toDecimal())->toBe('30.00')
            ->and($case->responsibilityChanges()->sole()->new_party)->toBe(ReturnParty::Partner)
            ->and($this->stock->refresh()->buckets())->toBe($before)
            ->and(RefundRequest::query()->count())->toBe(0)
            ->and(ReturnCharge::query()->count())->toBe(0);
    });

    it('can be a customer\'s request through the storefront, which is the Partner\'s too', function () {
        $case = rcwOpen(rcwDeliveredOrder(), parties: ['source' => OrderStatusChangeSource::Storefront]);

        expect($case->initiated_by_type)->toBe(ReturnInitiator::Customer)
            ->and($case->responsible_party)->toBe(ReturnParty::Partner);
    });

    it('is one case however many times the same request arrives', function () {
        $order = rcwDeliveredOrder();

        $first = rcwOpen($order, key: 'same-request');
        $second = rcwOpen($order, key: 'same-request');

        expect($second->id)->toBe($first->id)
            ->and(OrderReturn::query()->where('order_id', $order->id)->count())->toBe(1);
    });

    it('never lets a Partner declare Banij responsible, or open a case on Banij\'s behalf', function () {
        $order = rcwDeliveredOrder();

        expect(fn () => rcwOpen($order, parties: ['responsible' => ReturnParty::Banij]))
            ->toThrow(ReturnRefused::class)
            ->and(fn () => rcwOpen($order, parties: ['on_behalf_of' => ReturnParty::Banij]))
            ->toThrow(ReturnRefused::class)
            ->and(OrderReturn::query()->count())->toBe(0);
    });

    it('lets authorised staff open a case on behalf of Banij and state the responsible party', function () {
        $case = rcwOpen(rcwDeliveredOrder(), parties: [
            'source' => OrderStatusChangeSource::Staff,
            'initiator' => ReturnInitiator::Staff,
            'on_behalf_of' => ReturnParty::Banij,
            'responsible' => ReturnParty::Banij,
            'actor' => $this->manager,
        ]);

        expect($case->initiated_by_type)->toBe(ReturnInitiator::Staff)
            ->and($case->on_behalf_of)->toBe(ReturnParty::Banij)
            ->and($case->responsible_party)->toBe(ReturnParty::Banij)
            ->and($case->requested_by)->toBe($this->manager->id);
    });

    it('refuses staff who may not open cases', function () {
        $outsider = testPlatformStaff(PlatformRole::ContentManager);

        expect(fn () => rcwOpen(rcwDeliveredOrder(), parties: [
            'source' => OrderStatusChangeSource::Staff, 'initiator' => ReturnInitiator::Staff, 'actor' => $outsider,
        ]))->toThrow(AuthorizationException::class);
    });

    it('cannot claim more of a line than was sold, counting what earlier cases hold', function () {
        $order = rcwDeliveredOrder();

        rcwOpen($order, 2);

        expect(fn () => rcwOpen($order, 2))->toThrow(ReturnRefused::class);
    });
});

describe('the strict progression', function () {
    it('goes requested, approved, waiting for the parcel, received, under inspection, approved, settlement, completed', function () {
        $case = rcwOpen(rcwDeliveredOrder());

        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $statuses = $case->refresh()->statusHistory->pluck('new_status')->map->value->all();

        expect($statuses)->toBe([
            'requested', 'approved', 'waiting_for_parcel', 'parcel_received',
            'under_inspection', 'inspection_approved', 'settlement_pending',
        ]);
    });

    it('cannot skip a stage: the parcel is received before inspection, inspection before settlement', function () {
        $case = rcwOpen(rcwDeliveredOrder());

        expect(fn () => app(ReceiveReturnParcel::class)->handle($this->manager, $case))->toThrow(ReturnRefused::class);

        app(DecideOrderReturn::class)->approve($this->manager, $case, [], 'The goods are as described.');

        expect($case->refresh()->status)->toBe(ReturnStatus::WaitingForParcel)
            ->and(fn () => app(StartReturnInspection::class)->handle($this->manager, $case))->toThrow(ReturnRefused::class)
            ->and(fn () => app(FinalizeReturnInspection::class)->approve($this->manager, $case, [rcwLine($case)], $this->warehouse))
            ->toThrow(ReturnRefused::class)
            ->and(fn () => app(StartReturnSettlement::class)->handle($this->finance, $case))->toThrow(ReturnRefused::class);

        app(ReceiveReturnParcel::class)->handle($this->manager, $case);

        // Received, but not yet being inspected: judging the goods still has to wait.
        expect(fn () => app(FinalizeReturnInspection::class)->approve($this->manager, $case, [rcwLine($case)], $this->warehouse))
            ->toThrow(ReturnRefused::class);
    });

    it('records who received the parcel and when, once', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::WaitingForParcel);

        app(ReceiveReturnParcel::class)->handle($this->manager, $case);
        $first = $case->refresh();

        $later = testPlatformStaff(PlatformRole::OrderManager);
        app(ReceiveReturnParcel::class)->handle($later, $case);

        expect($first->received_by)->toBe($this->manager->id)
            ->and($first->received_at)->not->toBeNull()
            ->and($case->refresh()->received_by)->toBe($this->manager->id)
            ->and($case->statusHistory()->where('new_status', 'parcel_received')->count())->toBe(1);
    });

    it('does not continue once it has been rejected', function () {
        $case = rcwOpen(rcwDeliveredOrder());

        app(DecideOrderReturn::class)->reject($this->manager, $case, 'The photos show ordinary wear.');

        expect($case->refresh()->status)->toBe(ReturnStatus::Rejected)
            ->and(fn () => app(ReceiveReturnParcel::class)->handle($this->manager, $case))->toThrow(ReturnRefused::class)
            ->and(fn () => app(DecideOrderReturn::class)->approve($this->manager, $case, [], 'Changed our minds.'))->toThrow(ReturnRefused::class);
    });

    it('does not move to a stage the case workflow does not have', function () {
        $case = rcwOpen(rcwDeliveredOrder());

        expect($case->canTransitionTo(ReturnStatus::Received))->toBeFalse()
            ->and($case->canTransitionTo(ReturnStatus::Refunded))->toBeFalse()
            ->and($case->canTransitionTo(ReturnStatus::Approved))->toBeTrue();
    });

    it('cannot go back once settled: a completed case is only changed by compensating action', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);
        app(SettleReturnRefundManually::class)->handle($this->payments, $case, 'Handed over in cash, receipt 4471.', true, true);

        expect($case->refresh()->status)->toBe(ReturnStatus::Completed)
            ->and(fn () => $case->moveTo(ReturnStatus::Requested, new StatusChange(reason: 'x'), OrderStatusChangeSource::Staff))
            ->toThrow(IllegalStateTransition::class)
            ->and($case->canTransitionTo(ReturnStatus::UnderInspection))->toBeFalse();
    });
});

describe('inspection is the gate for stock and money', function () {
    it('restocks nothing, refunds nothing and charges nothing before the goods are approved', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        $before = $this->stock->refresh()->buckets();

        rcwAdvance($case, ReturnStatus::UnderInspection);

        expect($this->stock->refresh()->buckets())->toBe($before)
            ->and(RefundRequest::query()->count())->toBe(0)
            ->and(ReturnCharge::query()->count())->toBe(0)
            ->and(fn () => app(StartReturnSettlement::class)->handle($this->finance, $case))->toThrow(ReturnRefused::class);
    });

    it('puts approved, sellable units back on the shelf exactly once, through the stock ledger', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        $sold = $this->stock->refresh()->sold;

        rcwAdvance($case, ReturnStatus::InspectionApproved);

        $item = $case->items()->sole()->refresh();

        // A second finalisation finds the first and moves nothing.
        app(FinalizeReturnInspection::class)->approve($this->manager, $case, [rcwLine($case)], $this->warehouse);

        expect($this->stock->refresh()->sold)->toBe($sold - 2)
            ->and($this->stock->available)->toBe(19)
            ->and($item->isRestored())->toBeTrue()
            ->and($item->received_quantity)->toBe(2)
            ->and($item->disposition)->toBe(ReturnDisposition::Restock);
    });

    it('does not make a damaged unit sellable', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        $available = $this->stock->refresh()->available;

        rcwAdvance($case, ReturnStatus::UnderInspection);
        app(FinalizeReturnInspection::class)->approve($this->manager, $case, [rcwLine($case, ReturnDisposition::Damaged)], $this->warehouse);

        expect($this->stock->refresh()->available)->toBe($available)
            ->and($this->stock->damaged)->toBe(2);
    });

    it('needs every line judged, and refuses more than was approved', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::UnderInspection);

        expect(fn () => app(FinalizeReturnInspection::class)->approve($this->manager, $case, [], $this->warehouse))
            ->toThrow(ReturnRefused::class)
            ->and(fn () => app(FinalizeReturnInspection::class)->approve($this->manager, $case, [rcwLine($case, quantity: 3)], $this->warehouse))
            ->toThrow(ReturnRefused::class);
    });

    it('records a rejected inspection, restocks nothing, and gives the claim back', function () {
        $order = rcwDeliveredOrder();
        $case = rcwOpen($order);
        $before = $this->stock->refresh()->buckets();

        rcwAdvance($case, ReturnStatus::UnderInspection);
        app(FinalizeReturnInspection::class)->reject($this->manager, $case, 'The items were worn and washed.');

        expect($case->refresh()->status)->toBe(ReturnStatus::InspectionRejected)
            ->and($case->inspection_outcome)->toBe('rejected')
            ->and($case->inspected_by)->toBe($this->manager->id)
            ->and($this->stock->refresh()->buckets())->toBe($before)
            ->and(fn () => app(StartReturnSettlement::class)->handle($this->finance, $case))->toThrow(ReturnRefused::class);

        // The same goods may be asked for again.
        expect(rcwOpen($order, 2)->status)->toBe(ReturnStatus::Requested);
    });

    it('needs the inspect right, not just the right to decide', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::ParcelReceived);

        // Finance posts money; it does not judge goods.
        expect(fn () => app(StartReturnInspection::class)->handle($this->finance, $case))->toThrow(AuthorizationException::class);
    });
});

describe('what a return-only case costs', function () {
    it('is borne by Banij when Banij is responsible: recorded, and no wallet touched', function () {
        $wallet = websiteTestWallet($this->account, '500.00');
        $case = rcwOpen(rcwDeliveredOrder(), parties: [
            'source' => OrderStatusChangeSource::Staff, 'initiator' => ReturnInitiator::Staff,
            'on_behalf_of' => ReturnParty::Partner, 'responsible' => ReturnParty::Banij, 'actor' => $this->manager,
        ]);

        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $charge = rcwCharge($case);

        expect($charge->bearer)->toBe(ReturnParty::Banij)
            ->and($charge->status)->toBe(ReturnChargeStatus::Recorded)
            ->and($charge->amount->toDecimal())->toBe('30.00')
            ->and($charge->wallet_transaction_id)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('500.00');
    });

    it('is borne by the Partner when the Partner is responsible: half the delivery, once, from the wallet', function () {
        $wallet = websiteTestWallet($this->account, '500.00');
        $case = rcwOpen(rcwDeliveredOrder());

        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $charge = rcwCharge($case);

        // ৳30, not ৳60: the original delivery was collected once and is not charged again.
        expect($charge->bearer)->toBe(ReturnParty::Partner)
            ->and($charge->status)->toBe(ReturnChargeStatus::Posted)
            ->and($charge->amount->toDecimal())->toBe('30.00')
            ->and($wallet->refresh()->total->toDecimal())->toBe('470.00')
            ->and(LedgerEntry::query()->where('wallet_transaction_id', $charge->wallet_transaction_id)->sole()->type)
            ->toBe(LedgerTransactionType::ReturnChargeDebit);
    });

    it('refunds the goods that arrived and nothing for the delivery, after inspection', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);

        app(StartReturnSettlement::class)->handle($this->finance, $case);

        // Cash on delivery: no gateway can send it back, so a person settles it.
        expect($case->refresh()->refund_amount->toDecimal())->toBe('5200.00')
            ->and($case->refund_state)->toBe(ReturnRefundState::ManualReview)
            ->and($case->status)->toBe(ReturnStatus::SettlementPending);

        app(SettleReturnRefundManually::class)->handle($this->payments, $case, 'Handed over in cash, receipt 4471.', true, true);

        expect($case->refresh()->status)->toBe(ReturnStatus::Completed)
            ->and($case->refund_state)->toBe(ReturnRefundState::Completed)
            ->and($case->completed_at)->not->toBeNull();
    });

    it('adds an adjustment staff approve, with the reason, to the refund', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);

        app(StartReturnSettlement::class)->handle(
            $this->finance, $case, Money::fromDecimal('60.00', Currency::BDT), 'Delivery refunded: the parcel was late.',
        );

        expect($case->refresh()->refund_amount->toDecimal())->toBe('5260.00')
            ->and($case->other_adjustments->toDecimal())->toBe('60.00')
            ->and($case->adjustment_note)->toContain('parcel was late');
    });

    it('settles once however many times it is asked for', function () {
        websiteTestWallet($this->account, '500.00');
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);

        app(StartReturnSettlement::class)->handle($this->finance, $case);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        expect($case->charges()->count())->toBe(1)
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::ReturnChargeDebit->value)->count())->toBe(1)
            ->and($case->statusHistory()->where('new_status', 'settlement_pending')->count())->toBe(1);
    });

    it('needs the refund right to settle', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);

        expect(fn () => app(StartReturnSettlement::class)->handle($this->manager, $case))->toThrow(AuthorizationException::class);
    });
});

describe('a Partner who cannot cover a charge', function () {
    it('owes it, and is never driven below zero', function () {
        $wallet = websiteTestWallet($this->account, '10.00');
        $case = rcwOpen(rcwDeliveredOrder());

        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $charge = rcwCharge($case);

        expect($charge->status)->toBe(ReturnChargeStatus::Outstanding)
            ->and($charge->wallet_transaction_id)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('10.00');
    });

    it('is collected once, when the money is there, and not before', function () {
        $wallet = websiteTestWallet($this->account, '10.00');
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $charge = rcwCharge($case);

        expect(app(SettleOutstandingReturnCharge::class)->handle($charge))->toBeFalse();

        app(WalletService::class)->credit($wallet, LedgerTransactionType::TopUpCredit, Money::fromDecimal('100.00', Currency::BDT), new PostingContext(source: 'test', description: 'Top-up'));

        expect(app(SettleOutstandingReturnCharge::class)->handle($charge->refresh()))->toBeTrue()
            ->and(app(SettleOutstandingReturnCharge::class)->handle($charge->refresh()))->toBeFalse()
            ->and($charge->refresh()->status)->toBe(ReturnChargeStatus::Settled)
            ->and($wallet->refresh()->total->toDecimal())->toBe('80.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::ReturnChargeDebit->value)->count())->toBe(1);
    });
});

describe('changing who is responsible', function () {
    it('reverses the Partner\'s charge into the kinds of money it took, and records Banij as bearing it', function () {
        // Platform credit 20, then withdrawable 100: a ৳30 charge spends 20 of one and 10 of the other.
        $wallet = websiteTestWallet($this->account, '20.00');
        app(WalletService::class)->credit($wallet, LedgerTransactionType::SalesCredit, Money::fromDecimal('100.00', Currency::BDT), new PostingContext(source: 'test', description: 'Sales'));

        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $wallet->refresh();
        expect($wallet->total->toDecimal())->toBe('90.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00');

        app(ChangeReturnResponsibility::class)->handle($this->manager, $case, ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.');

        $wallet->refresh();
        $charges = ReturnCharge::query()->where('order_return_id', $case->id)->where('entry_type', 'charge')->orderBy('id')->get();

        // Typed balances preserved: the 20 of platform credit goes back as platform credit.
        expect($wallet->total->toDecimal())->toBe('120.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('20.00')
            ->and($charges)->toHaveCount(2)
            ->and($charges[0]->status)->toBe(ReturnChargeStatus::Reversed)
            ->and($charges[1]->bearer)->toBe(ReturnParty::Banij)
            ->and($charges[1]->status)->toBe(ReturnChargeStatus::Recorded)
            ->and($case->charges()->where('entry_type', 'reversal')->count())->toBe(1)
            ->and($case->refresh()->responsible_party)->toBe(ReturnParty::Banij)
            ->and($case->responsibilityChanges)->toHaveCount(2);

        $change = $case->responsibilityChanges->last();

        expect($change->previous_party)->toBe(ReturnParty::Partner)
            ->and($change->new_party)->toBe(ReturnParty::Banij)
            ->and($change->changed_by)->toBe($this->manager->id)
            ->and($change->reason)->toContain('fault is ours')
            // The original ledger entry is where it was: a correction is a new entry.
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::ReturnChargeDebit->value)->count())->toBe(2)
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::ReturnAdjustment->value)->count())->toBe(2);
    });

    it('is one compensating pair, however many times the same correction is asked for', function () {
        websiteTestWallet($this->account, '500.00');
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        app(ChangeReturnResponsibility::class)->handle($this->manager, $case, ReturnParty::Banij, 'Packed badly at the warehouse.');
        app(ChangeReturnResponsibility::class)->handle($this->manager, $case, ReturnParty::Banij, 'Packed badly at the warehouse.');

        expect($case->charges()->count())->toBe(3)
            ->and($case->responsibilityChanges()->count())->toBe(2);
    });

    it('can move the cost back to the Partner and charge their wallet, under a key of its own', function () {
        $wallet = websiteTestWallet($this->account, '500.00');
        $case = rcwOpen(rcwDeliveredOrder(), parties: [
            'source' => OrderStatusChangeSource::Staff, 'initiator' => ReturnInitiator::Staff,
            'responsible' => ReturnParty::Banij, 'actor' => $this->manager,
        ]);
        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        expect($wallet->refresh()->total->toDecimal())->toBe('500.00');

        app(ChangeReturnResponsibility::class)->handle($this->manager, $case, ReturnParty::Partner, 'The customer damaged it, as the photos show.');

        expect($wallet->refresh()->total->toDecimal())->toBe('470.00')
            ->and($case->charges()->where('entry_type', 'charge')->where('bearer', 'partner')->sole()->status)->toBe(ReturnChargeStatus::Posted);
    });

    it('is staff\'s to decide, with a reason', function () {
        $case = rcwOpen(rcwDeliveredOrder());

        expect(fn () => app(ChangeReturnResponsibility::class)->handle($this->finance, $case, ReturnParty::Banij, 'Packed badly at the warehouse.'))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ChangeReturnResponsibility::class)->handle($this->manager, $case, ReturnParty::Banij, 'x'))
            ->toThrow(ReturnRefused::class);
    });
});

describe('the records themselves', function () {
    it('never rewrite a charge: what it is cannot change, and it cannot be deleted', function () {
        websiteTestWallet($this->account, '500.00');
        $case = rcwOpen(rcwDeliveredOrder());
        rcwAdvance($case, ReturnStatus::InspectionApproved);
        app(StartReturnSettlement::class)->handle($this->finance, $case);

        $charge = rcwCharge($case);

        expect(fn () => DB::table('order_return_charges')->where('id', $charge->id)->update(['amount' => '1.00']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_return_charges')->where('id', $charge->id)->update(['bearer' => 'banij']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_return_charges')->where('id', $charge->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => $charge->delete())->toThrow(RuntimeException::class);
    });

    it('never rewrite attribution history', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        $row = $case->responsibilityChanges()->sole();

        expect(fn () => DB::table('order_return_responsibility_changes')->where('id', $row->id)->update(['new_party' => 'banij']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('order_return_responsibility_changes')->where('id', $row->id)->delete())
            ->toThrow(QueryException::class);
    });

    it('answers a charge only with a reversal that points at it', function () {
        $case = rcwOpen(rcwDeliveredOrder());

        expect(fn () => DB::table('order_return_charges')->insert([
            'public_id' => (string) Str::ulid(), 'order_return_id' => $case->id, 'business_account_id' => $case->business_account_id,
            'kind' => 'return_charge', 'entry_type' => 'reversal', 'reverses_charge_id' => null, 'bearer' => 'partner',
            'amount' => '1.00', 'currency_code' => 'BDT', 'status' => 'recorded', 'reason' => 'x',
            'idempotency_key' => 'bad', 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});

describe('who may see a case', function () {
    it('shows a Partner their own cases and no other Partner\'s, and staff the cases their role covers', function () {
        $case = rcwOpen(rcwDeliveredOrder());
        $other = websiteTestAccount();

        expect($this->account->owner->can('view', $case))->toBeTrue()
            ->and($other->owner->can('view', $case))->toBeFalse()
            ->and($this->manager->can('view', $case))->toBeTrue();
    });

    it('refuses the platform case permissions to any business identity, whatever it was handed', function () {
        $owner = $this->account->owner;
        $owner->givePermissionTo('return.view', 'return.inspect', 'damage.view');

        expect($owner->can('return.view'))->toBeFalse()
            ->and($owner->can('return.inspect'))->toBeFalse()
            ->and($owner->can('damage.view'))->toBeFalse()
            ->and(ReturnCasePolicy::isCaseAdministrationAbility('exchange.create'))->toBeTrue();
    });
});

describe('returns placed before the case workflow', function () {
    it('keep their three steps and are not restocked by the case workflow', function () {
        $order = rcwDeliveredOrder();
        $case = legacyReturnFlow(rcwOpen($order));
        $legacy = testPlatformStaff(PlatformRole::OrderManager);
        $legacy->assignRole(PlatformRole::InventoryManager->value);

        app(DecideOrderReturn::class)->approve($legacy, $case, [], 'The goods are as described.');

        expect($case->refresh()->status)->toBe(ReturnStatus::Approved)
            ->and(fn () => app(ReceiveReturnParcel::class)->handle($this->manager, $case))->toThrow(ReturnRefused::class);

        app(ReceiveReturnedItems::class)->handle($legacy, $case, [rcwLine($case)], $this->warehouse);

        expect($case->refresh()->status)->toBe(ReturnStatus::Received)
            ->and($case->canTransitionTo(ReturnStatus::UnderInspection))->toBeFalse()
            ->and($case->canTransitionTo(ReturnStatus::Refunded))->toBeTrue();
    });

    it('cannot be put through the case workflow\'s steps', function () {
        $case = legacyReturnFlow(rcwOpen(rcwDeliveredOrder()));
        app(DecideOrderReturn::class)->approve($this->manager, $case, [], 'The goods are as described.');

        expect(fn () => app(StartReturnInspection::class)->handle($this->manager, $case))->toThrow(ReturnRefused::class)
            ->and(fn () => app(StartReturnSettlement::class)->handle($this->finance, $case))->toThrow(ReturnRefused::class);
    });
});
