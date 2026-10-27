<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Address\Enums\AddressOwnerType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Damage\Actions\ApproveDamageLiability;
use App\Domain\Damage\Actions\ChangeDamageResponsibility;
use App\Domain\Damage\Actions\ConfirmDamageReceivedByPartner;
use App\Domain\Damage\Actions\DispatchDamage;
use App\Domain\Damage\Actions\FinalizeDamage;
use App\Domain\Damage\Actions\ReceiveDamageParcel;
use App\Domain\Damage\Actions\RecordDamageInspection;
use App\Domain\Damage\Actions\RejectDamage;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Actions\RequestDamageDelivery;
use App\Domain\Damage\Actions\SettleOutstandingDamageCharge;
use App\Domain\Damage\Actions\StartDamageInspection;
use App\Domain\Damage\Data\DamageInspection;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Enums\DamageDispatchStatus;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Exceptions\DamageRefused;
use App\Domain\Damage\Models\DamageCharge;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Damage\Queries\DamagePayload;
use App\Domain\Damage\Queries\MyDamages;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\FinalizeReturnInspection;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnParcel;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Actions\StartReturnInspection;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnChargeKind;
use App\Domain\Order\Enums\ReturnChargeStatus;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Models\Order;
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
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Damage cases (D35): found on goods that came back or reported on goods that
 * were delivered, inspected, decided, posted once, and — for the Partner who
 * bears the loss — delivered to them on request.
 *
 * Three panjabis at ৳2,600 each in the Partner's store, with a suggested selling
 * price of ৳2,500 frozen on the line when it was sold, are the vehicle. The
 * figures are read back: a damage of two is worth at most ৳5,000, whatever the
 * shop charged and whatever the product costs today.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Ayesha Fashion']);
    websiteTestFee(FeeType::WebsiteDelivery, 8000);

    // What a delivery costs, for any weight, anywhere: ৳80.
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

    $this->inspector = testPlatformStaff(PlatformRole::FulfillmentManager);
    $this->finance = testPlatformStaff(PlatformRole::FinanceManager);
    $this->orderManager = testPlatformStaff(PlatformRole::OrderManager);
    $this->partner = $this->account->owner;
});

/**
 * An order for three, delivered, with the Partner's own units sold.
 */
function dmgDeliveredOrder(bool $deliver = true): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-DM-'.Str::upper(Str::random(6)),
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

    if (! $deliver) {
        return $order;
    }

    foreach ($order->items()->with('stockReservation')->get() as $line) {
        app(StockReservations::class)->commit($line->stockReservation);
    }

    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test.'), OrderStatusChangeSource::System);
    }

    app(PartnerStock::class)->move(test()->stock, StockBucket::Processing, StockBucket::Sold, 3, PartnerStockMovementType::Adjustment);

    return $order->refresh();
}

/**
 * Report damage on a delivered line, as the Partner does unless `actor` says otherwise.
 *
 * @param  array<string, mixed>  $options
 */
function dmgReport(Order $order, int $quantity = 2, array $options = []): OrderDamage
{
    return app(ReportDamage::class)->fromDelivered(
        $options['actor'] ?? test()->partner,
        $order,
        $order->items()->sole()->public_id,
        new DamageReport(
            quantity: $quantity,
            condition: DamageCondition::Torn,
            description: $options['description'] ?? 'The fabric tore along the seam when the parcel was opened.',
            evidence: $options['evidence'] ?? [['type' => 'photo', 'reference' => 'evidence/panjabi-1.jpg']],
            parcelExpected: $options['parcel'] ?? true,
            idempotencyKey: $options['key'] ?? null,
        ),
    );
}

/**
 * @param  list<array{disposition: ReturnDisposition, quantity: int}>|null  $dispositions
 */
function dmgInspection(OrderDamage $damage, ?array $dispositions = null, DamageCause $cause = DamageCause::PartnerHandling): DamageInspection
{
    return new DamageInspection(
        dispositions: $dispositions ?? [['disposition' => ReturnDisposition::Damaged, 'quantity' => $damage->quantity]],
        cause: $cause,
        note: 'Seam torn through; not a wear mark.',
        warehouse: test()->warehouse,
    );
}

/**
 * A report taken through receipt, inspection and recorded findings: waiting at liability review.
 *
 * @param  list<array{disposition: ReturnDisposition, quantity: int}>|null  $dispositions
 */
function dmgReviewable(Order $order, int $quantity = 2, ?array $dispositions = null, DamageCause $cause = DamageCause::PartnerHandling): OrderDamage
{
    $damage = dmgReport($order, $quantity);

    app(ReceiveDamageParcel::class)->handle(test()->inspector, $damage, test()->warehouse);
    app(StartDamageInspection::class)->handle(test()->inspector, $damage);
    app(RecordDamageInspection::class)->handle(test()->inspector, $damage, dmgInspection($damage, $dispositions, $cause));

    return $damage->refresh();
}

function dmgApprove(OrderDamage $damage, ReturnParty $bearer = ReturnParty::Partner, string $loss = '5000.00', ?string $writeOff = null): OrderDamage
{
    return app(ApproveDamageLiability::class)->handle(
        test()->finance, $damage, $bearer, Money::fromDecimal($loss, Currency::BDT), 'Decided on the evidence and the inspection.', $writeOff,
    );
}

/**
 * Decided and posted.
 */
function dmgFinalized(Order $order, ReturnParty $bearer = ReturnParty::Partner, string $loss = '5000.00'): OrderDamage
{
    $damage = dmgReviewable($order);
    dmgApprove($damage, $bearer, $loss);

    return app(FinalizeDamage::class)->handle(test()->finance, $damage);
}

function dmgLossCharge(OrderDamage $damage, ?string $entryType = null): ?DamageCharge
{
    return $damage->charges()
        ->where('kind', ReturnChargeKind::DamageLoss->value)
        ->when($entryType !== null, fn ($query) => $query->where('entry_type', $entryType))
        ->latest('id')
        ->first();
}

/**
 * Whether the database refuses `$change`, inside its own savepoint.
 */
function dmgDatabaseRefuses(Closure $change): bool
{
    try {
        DB::transaction($change);
    } catch (QueryException) {
        return true;
    }

    return false;
}

describe('opening a damage', function () {
    it('freezes the product, the quantity and the suggested selling price from the order line', function () {
        $order = dmgDeliveredOrder();
        $damage = dmgReport($order);
        $line = $order->items()->sole();

        expect($damage->status)->toBe(DamageStatus::AwaitingReceipt)
            ->and($damage->reference)->toStartWith('DMG-')
            ->and($damage->order_id)->toBe($order->id)
            ->and($damage->order_item_id)->toBe($line->id)
            ->and($damage->business_account_id)->toBe($this->account->id)
            ->and($damage->website_id)->toBe($this->website->id)
            ->and($damage->sku)->toBe($line->sku)
            ->and($damage->quantity)->toBe(2)
            // The store charged ৳2,600; the platform suggested ৳2,500, and that is the value.
            ->and($line->suggested_unit_price->toDecimal())->toBe('2500.00')
            ->and($damage->valuation_unit_price->toDecimal())->toBe('2500.00')
            ->and($damage->max_recoverable_value->toDecimal())->toBe('5000.00')
            ->and($damage->valuation_basis->value)->toBe('suggested_price')
            ->and($damage->initiated_by_type->value)->toBe('partner')
            ->and($damage->responsible_party)->toBeNull()
            ->and($damage->approved_loss_amount)->toBeNull();
    });

    it('is not affected by the product being repriced afterwards', function () {
        $order = dmgDeliveredOrder();

        $this->selection->product->forceFill(['suggested_selling_price' => Money::fromDecimal('3900.00', Currency::BDT)])->save();

        $damage = dmgReport($order, 1);

        expect($damage->max_recoverable_value->toDecimal())->toBe('2500.00')
            ->and($damage->valuation_snapshot['unit_price'])->toBe('2500.00');
    });

    it('is not affected by what the Partner\'s own store charged', function () {
        $order = dmgDeliveredOrder();

        $this->selection->forceFill(['price' => Money::fromDecimal('3999.00')])->save();

        expect(dmgReport($order, 3)->max_recoverable_value->toDecimal())->toBe('7500.00');
    });

    it('is one case however many times the same report arrives', function () {
        $order = dmgDeliveredOrder();

        $first = dmgReport($order, 1, ['key' => 'same-report']);
        $second = dmgReport($order, 1, ['key' => 'same-report']);

        expect($second->id)->toBe($first->id)
            ->and(OrderDamage::query()->count())->toBe(1);
    });

    it('cannot claim more units than were sold, whatever is asked', function () {
        $order = dmgDeliveredOrder();

        dmgReport($order, 2);

        expect(fn () => dmgReport($order, 2))->toThrow(DamageRefused::class)
            ->and(OrderDamage::query()->count())->toBe(1)
            // And the database says the same when the application is bypassed.
            ->and(dmgDatabaseRefuses(function () use ($order) {
                $line = $order->items()->sole();
                $first = OrderDamage::query()->firstOrFail();

                DB::table('order_damages')->insert([
                    ...collect($first->getAttributes())->except(['id', 'public_id', 'reference', 'idempotency_key'])->map(
                        fn ($value) => is_array($value) ? json_encode($value) : $value,
                    )->all(),
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'DMG-OVER-'.Str::upper(Str::random(4)),
                    'quantity' => $line->quantity,
                ]);
            }))->toBeTrue();
    });

    it('needs a description or evidence, and is only for a delivered order', function () {
        $order = dmgDeliveredOrder();

        expect(fn () => dmgReport($order, 1, ['description' => 'short', 'evidence' => []]))->toThrow(DamageRefused::class);

        // An order that has not been delivered has nothing to report damaged.
        expect(fn () => dmgReport(dmgDeliveredOrder(deliver: false), 1))->toThrow(DamageRefused::class);
    });

    it('is only the Partner\'s own to report, or staff\'s on their behalf', function () {
        $order = dmgDeliveredOrder();
        $stranger = websiteTestAccount()->owner;

        expect(fn () => dmgReport($order, 1, ['actor' => $stranger]))->toThrow(AuthorizationException::class)
            ->and(fn () => dmgReport($order, 1, ['actor' => $this->finance]))->toThrow(AuthorizationException::class)
            ->and(OrderDamage::query()->count())->toBe(0);

        $byStaff = dmgReport($order, 1, ['actor' => $this->inspector]);

        expect($byStaff->initiated_by_type->value)->toBe('staff');
    });

    it('with no parcel expected goes straight to inspection and has nothing to move', function () {
        $order = dmgDeliveredOrder();

        $damage = dmgReport($order, 1, ['parcel' => false]);

        expect($damage->status)->toBe(DamageStatus::UnderInspection)
            ->and($damage->in_custody)->toBeFalse();
    });

    it('can be opened from returned goods only once the parcel was received and inspected, and not for units put back on sale', function () {
        $order = dmgDeliveredOrder();

        [$case] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
            reason: ReturnReason::NotAsDescribed,
            lines: [['sku' => $order->items()->sole()->sku, 'quantity' => 2]],
            customerNote: 'Torn.',
        ), OrderStatusChangeSource::Account);

        $line = $case->items()->sole();
        $report = new DamageReport(2, DamageCondition::Torn, 'Torn along the seam when it came back.');

        // The parcel has not even been asked for yet.
        expect(fn () => app(ReportDamage::class)->fromReturn($this->inspector, $case, $line, $report))->toThrow(DamageRefused::class);

        app(DecideOrderReturn::class)->approve($this->orderManager, $case, [], 'The goods are as described.');
        app(ReceiveReturnParcel::class)->handle($this->orderManager, $case);
        app(StartReturnInspection::class)->handle($this->orderManager, $case);

        // Received but not yet inspected.
        expect(fn () => app(ReportDamage::class)->fromReturn($this->inspector, $case, $line, $report))->toThrow(DamageRefused::class);

        app(FinalizeReturnInspection::class)->approve($this->orderManager, $case, [
            new ReturnedLine($line->public_id, 2, ReturnDisposition::Quarantine),
        ], $this->warehouse, 'Held for a closer look.');

        $damage = app(ReportDamage::class)->fromReturn($this->inspector, $case->refresh(), $line->refresh(), $report);

        expect($damage->status)->toBe(DamageStatus::Received)
            ->and($damage->source->value)->toBe('return_inspection')
            ->and($damage->order_return_id)->toBe($case->id)
            ->and($damage->order_return_item_id)->toBe($line->id)
            ->and($damage->in_custody)->toBeTrue()
            ->and($damage->held_bucket)->toBe(StockBucket::Returned)
            ->and($damage->received_at)->not->toBeNull()
            // One live case per returned line.
            ->and(app(ReportDamage::class)->fromReturn($this->inspector, $case, $line, $report)->id)->toBe($damage->id);
    });

    it('is refused for returned units that were put back on sale', function () {
        $order = dmgDeliveredOrder();

        [$case] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
            reason: ReturnReason::NotAsDescribed,
            lines: [['sku' => $order->items()->sole()->sku, 'quantity' => 2]],
            customerNote: 'Changed my mind.',
        ), OrderStatusChangeSource::Account);

        $line = $case->items()->sole();

        app(DecideOrderReturn::class)->approve($this->orderManager, $case, [], 'The goods are as described.');
        app(ReceiveReturnParcel::class)->handle($this->orderManager, $case);
        app(StartReturnInspection::class)->handle($this->orderManager, $case);
        app(FinalizeReturnInspection::class)->approve($this->orderManager, $case, [
            new ReturnedLine($line->public_id, 2, ReturnDisposition::Restock),
        ], $this->warehouse, 'Fit to sell.');

        expect(fn () => app(ReportDamage::class)->fromReturn($this->inspector, $case->refresh(), $line->refresh(), new DamageReport(2, DamageCondition::Torn, 'Torn along the seam.')))
            ->toThrow(DamageRefused::class);
    });
});

describe('the strict workflow', function () {
    it('cannot be inspected before the parcel has been received', function () {
        $damage = dmgReport(dmgDeliveredOrder());

        expect(fn () => app(StartDamageInspection::class)->handle($this->inspector, $damage))->toThrow(DamageRefused::class)
            ->and(fn () => app(RecordDamageInspection::class)->handle($this->inspector, $damage, dmgInspection($damage)))->toThrow(DamageRefused::class)
            ->and($damage->refresh()->status)->toBe(DamageStatus::AwaitingReceipt)
            ->and($this->stock->refresh()->damaged)->toBe(0);
    });

    it('cannot be decided or posted before inspection is complete', function () {
        $damage = dmgReport(dmgDeliveredOrder());
        app(ReceiveDamageParcel::class)->handle($this->inspector, $damage, $this->warehouse);
        app(StartDamageInspection::class)->handle($this->inspector, $damage);

        expect($damage->refresh()->status)->toBe(DamageStatus::UnderInspection)
            ->and(fn () => dmgApprove($damage))->toThrow(DamageRefused::class)
            ->and(fn () => app(FinalizeDamage::class)->handle($this->finance, $damage))->toThrow(DamageRefused::class)
            ->and($damage->charges()->count())->toBe(0);
    });

    it('cannot be posted until the loss is approved, and the database says so too', function () {
        $damage = dmgReviewable(dmgDeliveredOrder());

        expect($damage->status)->toBe(DamageStatus::LiabilityReview)
            ->and(fn () => app(FinalizeDamage::class)->handle($this->finance, $damage))->toThrow(DamageRefused::class)
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damages')->where('id', $damage->id)->update(['status' => 'finalized'])))->toBeTrue();
    });

    it('has no way back into an earlier stage', function () {
        $damage = dmgFinalized(dmgDeliveredOrder());

        expect($damage->canTransitionTo(DamageStatus::UnderInspection))->toBeFalse()
            ->and($damage->canTransitionTo(DamageStatus::LiabilityReview))->toBeFalse()
            ->and(fn () => app(RejectDamage::class)->handle($this->finance, $damage, 'Changed our minds about this one.'))->toThrow(DamageRefused::class)
            ->and(fn () => app(RecordDamageInspection::class)->handle($this->inspector, $damage, dmgInspection($damage)))->not->toThrow(DamageRefused::class);
    });

    it('keeps every step in a history that cannot be rewritten', function () {
        $damage = dmgFinalized(dmgDeliveredOrder());

        $path = $damage->statusHistory->pluck('new_status')->map(fn ($status) => $status->value)->all();

        expect($path)->toBe([
            'reported', 'awaiting_receipt', 'received', 'under_inspection', 'liability_review',
            'approved', 'financial_posting_pending', 'finalized',
        ])
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damage_status_history')->where('order_damage_id', $damage->id)->update(['reason' => 'x'])))->toBeTrue()
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damage_status_history')->where('order_damage_id', $damage->id)->delete()))->toBeTrue()
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damages')->where('id', $damage->id)->delete()))->toBeTrue();
    });

    it('is only for staff who hold the right for that step', function () {
        $damage = dmgReport(dmgDeliveredOrder());

        // A Partner and a finance manager cannot receive or inspect; staff who inspect cannot decide.
        expect(fn () => app(ReceiveDamageParcel::class)->handle($this->partner, $damage, $this->warehouse))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ReceiveDamageParcel::class)->handle($this->finance, $damage, $this->warehouse))->toThrow(AuthorizationException::class);

        $reviewable = dmgReviewable(dmgDeliveredOrder());

        expect(fn () => app(ApproveDamageLiability::class)->handle($this->inspector, $reviewable, ReturnParty::Partner, Money::fromDecimal('100.00', Currency::BDT), 'Decided on the evidence.'))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ApproveDamageLiability::class)->handle($this->partner, $reviewable, ReturnParty::Banij, Money::zero(Currency::BDT), 'Decided on the evidence.', 'Write it off, please.'))
            ->toThrow(AuthorizationException::class);

        dmgApprove($reviewable);

        // Deciding who bears a loss is not posting it.
        expect(fn () => app(FinalizeDamage::class)->handle($this->orderManager, $reviewable))->toThrow(AuthorizationException::class)
            ->and(Gate::forUser($this->partner)->allows('damage.view'))->toBeFalse();
    });
});

describe('inspection and stock', function () {
    it('puts undamaged units back on sale once, and closes a report that found no damage', function () {
        $order = dmgDeliveredOrder();
        $damage = dmgReport($order);

        app(ReceiveDamageParcel::class)->handle($this->inspector, $damage, $this->warehouse);

        $afterReceipt = $this->stock->refresh();
        expect($afterReceipt->sold)->toBe(1)
            ->and($afterReceipt->returned)->toBe(2);

        app(StartDamageInspection::class)->handle($this->inspector, $damage);

        $inspection = dmgInspection($damage, [['disposition' => ReturnDisposition::Restock, 'quantity' => 2]]);

        app(RecordDamageInspection::class)->handle($this->inspector, $damage, $inspection);
        // A repeat, or a racing duplicate, restocks nothing more.
        app(RecordDamageInspection::class)->handle($this->inspector, $damage, $inspection);

        $stock = $this->stock->refresh();

        expect($damage->refresh()->status)->toBe(DamageStatus::Rejected)
            ->and($stock->available)->toBe(19)
            ->and($stock->returned)->toBe(0)
            ->and($stock->damaged)->toBe(0)
            ->and($damage->dispositions()->count())->toBe(1)
            ->and(DB::table('partner_stock_movements')->where('source_type', 'order_damage')->where('source_id', $damage->id)->where('to_bucket', 'available')->count())->toBe(1)
            ->and($damage->charges()->count())->toBe(0);
    });

    it('holds damaged units where they can never be sold', function () {
        $order = dmgDeliveredOrder();
        $available = $this->stock->refresh()->available;

        $damage = dmgReviewable($order);
        $stock = $this->stock->refresh();

        expect($stock->damaged)->toBe(2)
            ->and($stock->returned)->toBe(0)
            ->and($stock->available)->toBe($available)
            ->and($damage->dispositions()->sole()->disposition)->toBe(ReturnDisposition::Damaged)
            // What a storefront can offer is unchanged by damaged units.
            ->and(app(PartnerStock::class)->availableFor($this->account, [['product_id' => $this->selection->product_id, 'variant_id' => null]]))->toBe([$this->selection->product_id.':' => $available]);
    });

    it('gives every unit exactly one disposition, however the inspection splits them', function () {
        $order = dmgDeliveredOrder();
        $damage = dmgReport($order);

        app(ReceiveDamageParcel::class)->handle($this->inspector, $damage, $this->warehouse);
        app(StartDamageInspection::class)->handle($this->inspector, $damage);

        // Too few, too many, and the same disposition twice: none is accepted.
        foreach ([
            [['disposition' => ReturnDisposition::Damaged, 'quantity' => 1]],
            [['disposition' => ReturnDisposition::Damaged, 'quantity' => 2], ['disposition' => ReturnDisposition::Restock, 'quantity' => 1]],
            [['disposition' => ReturnDisposition::Damaged, 'quantity' => 1], ['disposition' => ReturnDisposition::Damaged, 'quantity' => 1]],
        ] as $dispositions) {
            expect(fn () => app(RecordDamageInspection::class)->handle($this->inspector, $damage, dmgInspection($damage, $dispositions)))
                ->toThrow(DamageRefused::class);
        }

        expect($this->stock->refresh()->returned)->toBe(2);

        app(RecordDamageInspection::class)->handle($this->inspector, $damage, dmgInspection($damage, [
            ['disposition' => ReturnDisposition::Restock, 'quantity' => 1],
            ['disposition' => ReturnDisposition::Damaged, 'quantity' => 1],
        ]));

        $stock = $this->stock->refresh();

        expect($damage->refresh()->status)->toBe(DamageStatus::LiabilityReview)
            ->and($stock->available)->toBe(18)
            ->and($stock->damaged)->toBe(1)
            ->and($stock->returned)->toBe(0)
            ->and($damage->damagedQuantity())->toBe(1);
    });

    it('moves nothing for an item that stayed where it was, and records it only as damaged', function () {
        $damage = dmgReport(dmgDeliveredOrder(), 1, ['parcel' => false]);
        $before = $this->stock->refresh()->only(['available', 'sold', 'returned', 'damaged']);

        expect(fn () => app(RecordDamageInspection::class)->handle($this->inspector, $damage, dmgInspection($damage, [['disposition' => ReturnDisposition::Restock, 'quantity' => 1]])))
            ->toThrow(DamageRefused::class);

        app(RecordDamageInspection::class)->handle($this->inspector, $damage, dmgInspection($damage));

        expect($this->stock->refresh()->only(['available', 'sold', 'returned', 'damaged']))->toBe($before)
            ->and($damage->refresh()->status)->toBe(DamageStatus::LiabilityReview);
    });

    it('moves returned units that were held apart, from where they sit, and not twice', function () {
        $order = dmgDeliveredOrder();

        [$case] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
            reason: ReturnReason::NotAsDescribed,
            lines: [['sku' => $order->items()->sole()->sku, 'quantity' => 2]],
            customerNote: 'Torn.',
        ), OrderStatusChangeSource::Account);

        $line = $case->items()->sole();

        app(DecideOrderReturn::class)->approve($this->orderManager, $case, [], 'The goods are as described.');
        app(ReceiveReturnParcel::class)->handle($this->orderManager, $case);
        app(StartReturnInspection::class)->handle($this->orderManager, $case);
        app(FinalizeReturnInspection::class)->approve($this->orderManager, $case, [
            new ReturnedLine($line->public_id, 2, ReturnDisposition::Quarantine),
        ], $this->warehouse, 'Held for a closer look.');

        $heldAvailable = $this->stock->refresh()->available;
        expect($this->stock->returned)->toBe(2);

        $damage = app(ReportDamage::class)->fromReturn($this->inspector, $case->refresh(), $line->refresh(), new DamageReport(2, DamageCondition::Torn, 'Torn along the seam.'));
        app(StartDamageInspection::class)->handle($this->inspector, $damage);

        $inspection = dmgInspection($damage, [
            ['disposition' => ReturnDisposition::Restock, 'quantity' => 1],
            ['disposition' => ReturnDisposition::Damaged, 'quantity' => 1],
        ]);

        app(RecordDamageInspection::class)->handle($this->inspector, $damage, $inspection);
        app(RecordDamageInspection::class)->handle($this->inspector, $damage, $inspection);

        $stock = $this->stock->refresh();

        expect($stock->returned)->toBe(0)
            ->and($stock->available)->toBe($heldAvailable + 1)
            ->and($stock->damaged)->toBe(1);
    });
});

describe('what a damage is worth', function () {
    it('cannot be approved above the most it was worth when sold, below nothing, or in another currency', function () {
        $damage = dmgReviewable(dmgDeliveredOrder());

        expect(fn () => dmgApprove($damage, loss: '5000.01'))->toThrow(DamageRefused::class)
            ->and(fn () => app(ApproveDamageLiability::class)->handle($this->finance, $damage, ReturnParty::Partner, Money::fromDecimal('-1.00', Currency::BDT), 'Decided on the evidence.'))
            ->toThrow(DamageRefused::class)
            ->and(fn () => app(ApproveDamageLiability::class)->handle($this->finance, $damage, ReturnParty::Partner, Money::fromDecimal('10.00', Currency::USD), 'Decided on the evidence.'))
            ->toThrow(DamageRefused::class)
            // Nothing is not a decision without a reason.
            ->and(fn () => dmgApprove($damage, loss: '0.00'))->toThrow(DamageRefused::class)
            ->and($damage->refresh()->status)->toBe(DamageStatus::LiabilityReview)
            ->and($damage->approved_loss_amount)->toBeNull();
    });

    it('may be approved for less, to the exact cent', function () {
        $damage = dmgApprove(dmgReviewable(dmgDeliveredOrder()), loss: '3333.33');

        expect($damage->status)->toBe(DamageStatus::FinancialPostingPending)
            ->and($damage->approved_loss_amount->toDecimal())->toBe('3333.33')
            ->and($damage->approval_reason)->not->toBeNull()
            ->and($damage->liability_decided_at)->not->toBeNull();
    });

    it('is capped at the units found damaged, not the units reported', function () {
        $damage = dmgReviewable(dmgDeliveredOrder(), 2, [
            ['disposition' => ReturnDisposition::Restock, 'quantity' => 1],
            ['disposition' => ReturnDisposition::Damaged, 'quantity' => 1],
        ]);

        expect(fn () => dmgApprove($damage, loss: '2500.01'))->toThrow(DamageRefused::class);

        expect(dmgApprove($damage, loss: '2500.00')->approved_loss_amount->toDecimal())->toBe('2500.00');
    });

    it('can be written off with a reason, posting nothing', function () {
        $damage = dmgApprove(dmgReviewable(dmgDeliveredOrder()), ReturnParty::Banij, '0.00', 'Customer already compensated by the courier.');
        $damage = app(FinalizeDamage::class)->handle($this->finance, $damage);

        expect($damage->status)->toBe(DamageStatus::Finalized)
            ->and($damage->write_off_reason)->toBe('Customer already compensated by the courier.')
            ->and($damage->charges()->count())->toBe(0);
    });

    it('is fixed once decided, and the database refuses a change', function () {
        $damage = dmgApprove(dmgReviewable(dmgDeliveredOrder()));

        expect(dmgDatabaseRefuses(fn () => DB::table('order_damages')->where('id', $damage->id)->update(['approved_loss_amount' => '1.00'])))->toBeTrue()
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damages')->where('id', $damage->id)->update(['max_recoverable_value' => '9999.00'])))->toBeTrue()
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damages')->where('id', $damage->id)->update(['quantity' => 1])))->toBeTrue()
            ->and($damage->refresh()->approved_loss_amount->toDecimal())->toBe('5000.00');
    });

    it('has nothing to value an order line that froze nothing, and can only be written off', function () {
        $order = dmgDeliveredOrder();
        DB::statement('ALTER TABLE order_items DISABLE TRIGGER order_items_history_guard');
        DB::table('order_items')->where('order_id', $order->id)->update(['suggested_unit_price' => null]);
        DB::statement('ALTER TABLE order_items ENABLE TRIGGER order_items_history_guard');

        $damage = dmgReviewable($order->refresh());

        expect($damage->valuation_basis->value)->toBe('none')
            ->and($damage->max_recoverable_value->toDecimal())->toBe('0.00')
            ->and(fn () => dmgApprove($damage, loss: '0.01'))->toThrow(DamageRefused::class);
    });
});

describe('Banij responsibility', function () {
    it('records the loss in Banij accounting and leaves the Partner whole', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder(), ReturnParty::Banij);

        $charge = dmgLossCharge($damage);

        expect($charge->bearer)->toBe(ReturnParty::Banij)
            ->and($charge->status)->toBe(ReturnChargeStatus::Recorded)
            ->and($charge->amount->toDecimal())->toBe('5000.00')
            ->and($charge->wallet_transaction_id)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('10000.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageLossDebit->value)->count())->toBe(0)
            // Not the Partner's liability, and not on their list of damages.
            ->and(app(MyDamages::class)->paginate($this->account)->total())->toBe(0);
    });
});

describe('Partner responsibility', function () {
    it('debits the Partner\'s wallet once, and shows the item under My Damages', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        $charge = dmgLossCharge($damage);

        expect($charge->bearer)->toBe(ReturnParty::Partner)
            ->and($charge->status)->toBe(ReturnChargeStatus::Posted)
            ->and($charge->wallet_transaction_id)->not->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('5000.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageLossDebit->value)->count())->toBe(1)
            ->and(app(MyDamages::class)->paginate($this->account)->total())->toBe(1);
    });

    it('spends platform credit first and remembers the exact split', function () {
        // ৳3,000 of platform credit, then ৳4,000 withdrawable: a ৳5,000 loss takes 3,000 of one and 2,000 of the other.
        $wallet = websiteTestWallet($this->account, '3000.00');
        app(WalletService::class)->credit($wallet, LedgerTransactionType::SalesCredit, Money::fromDecimal('4000.00', Currency::BDT), new PostingContext(source: 'test', description: 'Sales'));

        $damage = dmgFinalized(dmgDeliveredOrder());
        $wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('2000.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('0.00')
            ->and($damage->charges()->sole()->walletTransaction->platform_credit_amount->toDecimal())->toBe('3000.00');
    });

    it('leaves an outstanding liability, never a negative balance, when the wallet cannot cover it', function () {
        $wallet = websiteTestWallet($this->account, '1000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        $charge = dmgLossCharge($damage);

        expect($charge->status)->toBe(ReturnChargeStatus::Outstanding)
            ->and($charge->wallet_transaction_id)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('1000.00');

        // Not collected while the money is not there…
        expect(app(SettleOutstandingDamageCharge::class)->handle($charge, $this->finance))->toBeFalse();

        // …and collected exactly once when it is.
        app(WalletService::class)->credit($wallet, LedgerTransactionType::SalesCredit, Money::fromDecimal('9000.00', Currency::BDT), new PostingContext(source: 'test', description: 'Sales'));

        expect(app(SettleOutstandingDamageCharge::class)->handle($charge, $this->finance))->toBeTrue()
            ->and(app(SettleOutstandingDamageCharge::class)->handle($charge->refresh(), $this->finance))->toBeFalse()
            ->and($charge->refresh()->status)->toBe(ReturnChargeStatus::Settled)
            ->and($wallet->refresh()->total->toDecimal())->toBe('5000.00')
            // One collection, not two (two ledger entries only because it spends two kinds of money).
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageLossDebit->value)->distinct()->count('wallet_transaction_id'))->toBe(1);
    });

    it('posts once however many times it is finalized', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        app(FinalizeDamage::class)->handle($this->finance, $damage);
        app(FinalizeDamage::class)->handle($this->finance, $damage->refresh());

        expect($damage->charges()->count())->toBe(1)
            ->and($wallet->refresh()->total->toDecimal())->toBe('5000.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageLossDebit->value)->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'damage.finalized')->where('auditable_id', $damage->id)->count())->toBe(1)
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damage_charges')->where('order_damage_id', $damage->id)->update(['amount' => '1.00'])))->toBeTrue()
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damage_charges')->where('order_damage_id', $damage->id)->delete()))->toBeTrue();
    });
});

describe('correcting who bears it', function () {
    it('reverses a Partner debit into the kinds of money it took, and records Banij as bearing it', function () {
        $wallet = websiteTestWallet($this->account, '3000.00');
        app(WalletService::class)->credit($wallet, LedgerTransactionType::SalesCredit, Money::fromDecimal('4000.00', Currency::BDT), new PostingContext(source: 'test', description: 'Sales'));

        $damage = dmgFinalized(dmgDeliveredOrder());
        $original = dmgLossCharge($damage);
        $entries = LedgerEntry::query()->where('type', LedgerTransactionType::DamageLossDebit->value)->orderBy('id')->get();
        $entry = $entries->first();

        app(ChangeDamageResponsibility::class)->handle($this->finance, $damage, ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.');

        $wallet->refresh();

        expect($wallet->total->toDecimal())->toBe('7000.00')
            ->and($wallet->platformCredit()->toDecimal())->toBe('3000.00')
            ->and($original->refresh()->status)->toBe(ReturnChargeStatus::Reversed)
            // The compensating pair: one reversal, one new charge to Banij. Nothing edited or deleted.
            ->and($damage->charges()->count())->toBe(3)
            ->and($damage->charges()->where('entry_type', 'reversal')->count())->toBe(1)
            ->and($damage->charges()->where('entry_type', 'charge')->where('bearer', 'banij')->where('status', 'recorded')->count())->toBe(1)
            // Each kind of money is answered by an entry pointing at the entry that took it.
            ->and($entries)->toHaveCount(2)
            ->and(LedgerEntry::query()->whereIn('corrects_ledger_entry_id', $entries->pluck('id'))->count())->toBe(2)
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageLossDebit->value)->count())->toBe(2)
            // The old entry is exactly as it was written.
            ->and(LedgerEntry::query()->findOrFail($entry->id)->getAttributes())->toBe($entry->getAttributes())
            ->and($damage->refresh()->responsible_party)->toBe(ReturnParty::Banij)
            ->and($damage->responsibilityChanges()->count())->toBe(2)
            ->and(app(MyDamages::class)->paginate($this->account)->total())->toBe(0);
    });

    it('makes one pair, not two, when the same correction is asked for twice', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        $correct = fn () => app(ChangeDamageResponsibility::class)->handle($this->finance, $damage->refresh(), ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.');

        $correct();
        $correct();

        expect($damage->charges()->count())->toBe(3)
            ->and($damage->responsibilityChanges()->count())->toBe(2)
            ->and(AuditLog::query()->where('action', 'damage.responsibility_changed')->where('auditable_id', $damage->id)->count())->toBe(1);
    });

    it('can move to the Partner and back again, each time one pair under its own revision', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder(), ReturnParty::Banij);

        app(ChangeDamageResponsibility::class)->handle($this->finance, $damage, ReturnParty::Partner, 'The customer used it roughly: not our fault.');

        expect($wallet->refresh()->total->toDecimal())->toBe('5000.00');

        app(ChangeDamageResponsibility::class)->handle($this->finance, $damage->refresh(), ReturnParty::Banij, 'On review it was our packing after all.');

        expect($wallet->refresh()->total->toDecimal())->toBe('10000.00')
            ->and($damage->charges()->where('entry_type', 'reversal')->count())->toBe(2)
            ->and($damage->responsibilityChanges()->pluck('new_party')->map(fn ($party) => $party->value)->all())->toBe(['banij', 'partner', 'banij']);
    });

    it('needs a reason, the right to assign liability, and an item not already on its way', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        expect(fn () => app(ChangeDamageResponsibility::class)->handle($this->finance, $damage, ReturnParty::Banij, 'no'))->toThrow(DamageRefused::class)
            ->and(fn () => app(ChangeDamageResponsibility::class)->handle($this->inspector, $damage, ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ChangeDamageResponsibility::class)->handle($this->partner, $damage, ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.'))->toThrow(AuthorizationException::class);

        app(RequestDamageDelivery::class)->handle($this->partner, $damage, addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id)->public_id);

        expect(fn () => app(ChangeDamageResponsibility::class)->handle($this->finance, $damage->refresh(), ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.'))
            ->toThrow(DamageRefused::class);
    });

    it('is not available before the loss is decided: that is the decision\'s own job', function () {
        $damage = dmgReviewable(dmgDeliveredOrder());

        expect(fn () => app(ChangeDamageResponsibility::class)->handle($this->finance, $damage, ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.'))
            ->toThrow(DamageRefused::class);
    });
});

describe('My Damages', function () {
    it('shows a Partner only their own, with only safe fields', function () {
        websiteTestWallet($this->account, '10000.00');
        $mine = dmgFinalized(dmgDeliveredOrder());

        $other = websiteTestAccount();
        $listed = app(MyDamages::class)->paginate($this->account);
        $payload = app(DamagePayload::class)->forPartner($mine->refresh());

        expect($listed->total())->toBe(1)
            ->and(app(MyDamages::class)->paginate($other)->total())->toBe(0)
            ->and(app(MyDamages::class)->find($other, $mine->public_id))->toBeNull()
            ->and(app(MyDamages::class)->find($this->account, $mine->public_id)?->id)->toBe($mine->id)
            ->and($payload)->toHaveKeys([
                'reference', 'order_number', 'product', 'quantity', 'cause', 'approved_loss', 'responsibility',
                'dispositions', 'storage_state', 'delivery_request', 'timeline',
            ])
            ->and($payload['order_number'])->toBe($mine->order->reference)
            ->and($payload['approved_loss']['amount'] ?? $payload['approved_loss'])->not->toBeNull()
            ->and($payload['storage_state'])->toBe('held_by_banij')
            ->and($payload['delivery_request']['state'])->toBe('available');

        $json = json_encode($payload);

        // Nothing about the customer, the warehouse or the people inside Banij.
        foreach (['Ayesha Rahman', '+88017', 'House 12', 'warehouse', 'internal_note', 'valuation'] as $leak) {
            expect($json)->not->toContain($leak);
        }
    });

    it('denies another Partner every way in', function () {
        websiteTestWallet($this->account, '10000.00');
        $mine = dmgFinalized(dmgDeliveredOrder());
        $stranger = websiteTestAccount()->owner;

        expect(Gate::forUser($stranger)->allows('view', $mine))->toBeFalse()
            ->and(Gate::forUser($this->partner)->allows('view', $mine))->toBeTrue()
            ->and(Gate::forUser($stranger)->allows('requestDelivery', $mine))->toBeFalse()
            ->and(fn () => app(RequestDamageDelivery::class)->handle($stranger, $mine, addressTestCreate(AddressOwnerType::BusinessAccount, $stranger->businessAccount->id)->public_id))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(ConfirmDamageReceivedByPartner::class)->handle($stranger, $mine))->toThrow(AuthorizationException::class);
    });

    it('does not list a case until its loss is decided, or one Banij bears', function () {
        websiteTestWallet($this->account, '10000.00');

        dmgReviewable(dmgDeliveredOrder());
        dmgFinalized(dmgDeliveredOrder(), ReturnParty::Banij);

        expect(app(MyDamages::class)->paginate($this->account)->total())->toBe(0);
    });
});

describe('delivering a damaged item to the Partner', function () {
    function dmgAddress(?int $accountId = null): string
    {
        return addressTestCreate(AddressOwnerType::BusinessAccount, $accountId ?? test()->account->id)->public_id;
    }

    it('works out the delivery charge on the server and debits it from the wallet once', function () {
        $wallet = websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        $dispatch = app(RequestDamageDelivery::class)->handle($this->partner, $damage, dmgAddress());
        // A retry, or a double click, pays nothing more and makes no second dispatch.
        $again = app(RequestDamageDelivery::class)->handle($this->partner, $damage->refresh(), dmgAddress());

        expect($dispatch->delivery_charge->toDecimal())->toBe('80.00')
            ->and($dispatch->quantity)->toBe(2)
            ->and($dispatch->status)->toBe(DamageDispatchStatus::Requested)
            ->and($again->id)->toBe($dispatch->id)
            ->and($wallet->refresh()->total->toDecimal())->toBe('4920.00')
            ->and(LedgerEntry::query()->where('type', LedgerTransactionType::DamageDeliveryDebit->value)->count())->toBe(1)
            ->and($damage->charges()->where('kind', 'damage_delivery')->count())->toBe(1)
            ->and($damage->refresh()->status)->toBe(DamageStatus::DispatchRequested)
            ->and($dispatch->address_snapshot)->toHaveKey('detailedAddress');
    });

    it('takes no amount from the request: it is the delivery service\'s figure whatever the rule is', function () {
        websiteTestWallet($this->account, '10000.00');
        DeliveryChargeRule::query()->update(['base_charge' => '125.50']);

        $damage = dmgFinalized(dmgDeliveredOrder());
        $dispatch = app(RequestDamageDelivery::class)->handle($this->partner, $damage, dmgAddress());

        expect($dispatch->delivery_charge->toDecimal())->toBe('125.50')
            ->and($dispatch->delivery_calculation)->toHaveKey('final_charge');
    });

    it('is refused when the wallet cannot pay for it, and creates nothing', function () {
        // Enough for the loss (৳5,000) and ৳50 over: the ৳80 delivery cannot be covered.
        $wallet = websiteTestWallet($this->account, '5050.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        expect(dmgLossCharge($damage)->status)->toBe(ReturnChargeStatus::Posted)
            ->and(fn () => app(RequestDamageDelivery::class)->handle($this->partner, $damage, dmgAddress()))->toThrow(DamageRefused::class)
            ->and($damage->refresh()->status)->toBe(DamageStatus::Finalized)
            ->and($damage->dispatch)->toBeNull()
            ->and($wallet->refresh()->total->toDecimal())->toBe('50.00');
    });

    it('goes only to one of the Partner\'s own addresses', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());
        $strangers = dmgAddress(websiteTestAccount()->id);

        expect(fn () => app(RequestDamageDelivery::class)->handle($this->partner, $damage, $strangers))->toThrow(DamageRefused::class)
            ->and(fn () => app(RequestDamageDelivery::class)->handle($this->partner, $damage, 'not-an-address'))->toThrow(DamageRefused::class)
            ->and($damage->refresh()->dispatch)->toBeNull();
    });

    it('is only for an item the Partner bears the loss for, with damaged units in hand', function () {
        websiteTestWallet($this->account, '10000.00');

        $banij = dmgFinalized(dmgDeliveredOrder(), ReturnParty::Banij);
        $undecided = dmgReviewable(dmgDeliveredOrder(), 1);

        expect(fn () => app(RequestDamageDelivery::class)->handle($this->partner, $banij, dmgAddress()))->toThrow(DamageRefused::class)
            ->and(fn () => app(RequestDamageDelivery::class)->handle($this->partner, $undecided, dmgAddress()))->toThrow(DamageRefused::class);
    });

    it('cannot be dispatched twice, and the item stays out of sellable stock throughout', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());
        $before = $this->stock->refresh()->only(['available', 'sold', 'damaged']);

        $requested = app(RequestDamageDelivery::class)->handle($this->partner, $damage, dmgAddress());

        // Not before it is asked for, not without a tracking reference, and only by staff who dispatch.
        expect(fn () => app(DispatchDamage::class)->handle($this->partner, $damage, 'TRK-1'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(DispatchDamage::class)->handle($this->inspector, $damage, '  '))->toThrow(DamageRefused::class);

        $dispatch = app(DispatchDamage::class)->handle($this->inspector, $damage, 'TRK-1001');
        app(DispatchDamage::class)->handle($this->inspector, $damage->refresh(), 'TRK-1002');

        $after = $this->stock->refresh();

        expect($dispatch->id)->toBe($requested->id)
            ->and($dispatch->status)->toBe(DamageDispatchStatus::Dispatched)
            ->and($dispatch->tracking_number)->toBe('TRK-1001')
            ->and($damage->refresh()->status)->toBe(DamageStatus::Dispatched)
            // The two damaged units left once, and nothing became sellable.
            ->and($after->damaged)->toBe($before['damaged'] - 2)
            ->and($after->available)->toBe($before['available'])
            ->and($after->sold)->toBe($before['sold'])
            ->and(DB::table('partner_stock_movements')->where('source_type', 'order_damage')->where('source_id', $damage->id)->whereNull('to_bucket')->count())->toBe(1)
            // And a second dispatch for the same units cannot even be written.
            ->and(dmgDatabaseRefuses(fn () => DB::table('order_damage_dispatches')->insert([
                ...collect($dispatch->getAttributes())->except(['id', 'public_id', 'idempotency_key'])->map(
                    fn ($value) => is_array($value) ? json_encode($value) : $value,
                )->all(),
                'public_id' => (string) Str::ulid(),
                'idempotency_key' => 'second-dispatch',
            ])))->toBeTrue();
    });

    it('records that the Partner received it, once, by them or by staff', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        app(RequestDamageDelivery::class)->handle($this->partner, $damage, dmgAddress());

        expect(fn () => app(ConfirmDamageReceivedByPartner::class)->handle($this->partner, $damage))->toThrow(DamageRefused::class);

        app(DispatchDamage::class)->handle($this->inspector, $damage, 'TRK-1001');

        $received = app(ConfirmDamageReceivedByPartner::class)->handle($this->partner, $damage);
        app(ConfirmDamageReceivedByPartner::class)->handle($this->partner, $damage->refresh());

        expect($received->status)->toBe(DamageDispatchStatus::ReceivedByPartner)
            ->and($damage->refresh()->status)->toBe(DamageStatus::ReceivedByPartner)
            ->and($damage->status->isTerminal())->toBeTrue()
            ->and(app(DamagePayload::class)->forPartner($damage)['storage_state'])->toBe('with_partner')
            ->and(app(DamagePayload::class)->forPartner($damage)['delivery_request']['state'])->toBe('delivered')
            ->and(AuditLog::query()->where('action', 'damage.received_by_partner')->where('auditable_id', $damage->id)->count())->toBe(1);
    });
});

describe('what is written down', function () {
    it('keeps audit metadata free of the customer\'s details', function () {
        websiteTestWallet($this->account, '10000.00');
        $damage = dmgFinalized(dmgDeliveredOrder());

        app(RequestDamageDelivery::class)->handle($this->partner, $damage, addressTestCreate(AddressOwnerType::BusinessAccount, $this->account->id)->public_id);

        $entries = AuditLog::query()->where('auditable_type', OrderDamage::class)->where('auditable_id', $damage->id)->get();
        $json = json_encode($entries->map(fn ($entry) => [$entry->before, $entry->after, $entry->reason, $entry->note])->all());

        expect($entries->pluck('action')->all())->toContain('damage.reported', 'damage.received', 'damage.inspected', 'damage.liability_approved', 'damage.finalized', 'damage.delivery_requested');

        foreach (['Ayesha Rahman', '+88017', 'House 12', 'customer'] as $leak) {
            expect($json)->not->toContain($leak);
        }
    });

    it('is read in English and in Bangla', function () {
        $damage = dmgFinalized(dmgDeliveredOrder());

        app()->setLocale('en');
        $english = [$damage->status->label(), $damage->cause?->label(), $damage->damage_condition->label()];

        app()->setLocale('bn');
        $bangla = [$damage->status->label(), $damage->cause?->label(), $damage->damage_condition->label()];

        app()->setLocale('en');

        expect($english)->toBe(['Finalized', 'Partner handling', 'Torn'])
            ->and($bangla)->not->toBe($english)
            ->and($bangla[0])->toBe('চূড়ান্ত')
            ->and(__('damages.notes.approved.partner', [], 'bn'))->not->toBe(__('damages.notes.approved.partner', [], 'en'));
    });
});
