<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\DeliveryChargeRule;
use App\Domain\Damage\Actions\ApproveDamageLiability;
use App\Domain\Damage\Actions\FinalizeDamage;
use App\Domain\Damage\Actions\ReceiveDamageParcel;
use App\Domain\Damage\Actions\RecordDamageInspection;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Actions\StartDamageInspection;
use App\Domain\Damage\Data\DamageInspection;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Models\OrderDamage;
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
use App\Domain\Order\Enums\ReturnCaseType;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/*
 * Fixtures for the return, exchange and damage screens (D35).
 *
 * Here rather than in one test file because Pest loads every test into one
 * global namespace: a helper defined in one file and called from another works
 * only while both happen to be loaded.
 *
 * The vehicle is three panjabis at ৳2,600 in one Partner's store, with a
 * suggested selling price of ৳2,500 and a ৳80 delivery, delivered from the
 * Partner's own stock.
 */

/**
 * The world a case screen test needs: roles, a Partner with a store and stock, a
 * warehouse, a delivery rule, and one person for each job.
 */
function caseTestSetUp(): void
{
    test()->seed(RolesAndPermissionsSeeder::class);

    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    test()->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    test()->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    test()->website = Website::factory()->forAccount(test()->account)->active()->create(['name' => 'Ayesha Fashion']);
    websiteTestFee(FeeType::WebsiteDelivery, 8000);

    DeliveryChargeRule::create([
        'weight_from_grams' => 0,
        'currency_code' => 'BDT',
        'base_charge' => Money::fromDecimal('80.00', Currency::BDT),
        'priority' => 0,
        'effective_from' => CarbonImmutable::now()->subDay(),
        'is_active' => true,
    ]);

    test()->selection = WebsiteProduct::create([
        'website_id' => test()->website->id,
        'business_account_id' => test()->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00'),
        'published_at' => now(),
    ]);

    test()->stock = websiteTestOwnsSelection(test()->selection, 30);

    test()->partner = test()->account->owner;
    test()->viewer = testPlatformStaff(PlatformRole::FinanceManager);
    test()->inspector = testPlatformStaff(PlatformRole::FulfillmentManager);
    test()->orderManager = testPlatformStaff(PlatformRole::OrderManager);
}

/**
 * Staff holding exactly these permissions and no role: for a test about who sees what.
 *
 * @param  list<string>  $permissions
 */
function caseTestStaffWith(array $permissions): User
{
    $user = User::factory()->staff()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * An order for three, delivered, with the Partner's own units sold.
 */
function caseTestDeliveredOrder(?Website $website = null, ?Closure $mutate = null): Order
{
    $website ??= test()->website;
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
        reference: 'SF-CS-'.Str::upper(Str::random(6)),
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
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test.'), OrderStatusChangeSource::System);
    }

    app(PartnerStock::class)->move(test()->stock, StockBucket::Processing, StockBucket::Sold, 3, PartnerStockMovementType::Adjustment);

    return $order->refresh();
}

/**
 * A case opened by the Partner, as they do from their panel.
 *
 * @param  array<string, mixed>  $options
 */
function caseTestOpenReturn(Order $order, int $quantity = 2, ReturnCaseType $type = ReturnCaseType::Return, array $options = []): OrderReturn
{
    [$case] = app(RequestOrderReturn::class)->handle($order, new ReturnSubmission(
        reason: ReturnReason::NotAsDescribed,
        lines: [['sku' => $order->items()->sole()->sku, 'quantity' => $quantity]],
        customerNote: 'The size is wrong.',
        idempotencyKey: $options['key'] ?? null,
        caseType: $type,
        replacements: $options['replacements'] ?? [],
    ), OrderStatusChangeSource::Account, test()->partner);

    return $case;
}

/**
 * Take a case through the stages, as staff do, up to and including `$to`.
 */
function caseTestAdvance(OrderReturn $case, ReturnStatus $to, ReturnDisposition $disposition = ReturnDisposition::Restock): OrderReturn
{
    $staff = test()->orderManager;

    $next = [
        ReturnStatus::Requested->value => fn () => app(DecideOrderReturn::class)->approve($staff, $case, [], 'The goods are as described.'),
        ReturnStatus::WaitingForParcel->value => fn () => app(ReceiveReturnParcel::class)->handle($staff, $case),
        ReturnStatus::ParcelReceived->value => fn () => app(StartReturnInspection::class)->handle($staff, $case),
        ReturnStatus::UnderInspection->value => function () use ($staff, $case, $disposition) {
            $line = $case->items()->sole();

            app(FinalizeReturnInspection::class)->approve(
                $staff, $case, [new ReturnedLine($line->public_id, (int) $line->approved_quantity, $disposition)], test()->warehouse, 'Checked.',
            );
        },
    ];

    while ($case->refresh()->status !== $to && isset($next[$case->status->value])) {
        $next[$case->status->value]();
    }

    return $case->refresh();
}

/**
 * A damage reported by the Partner, received and inspected as damaged: waiting at liability review.
 */
function caseTestDamageUnderReview(Order $order, int $quantity = 2): OrderDamage
{
    $damage = app(ReportDamage::class)->fromDelivered(
        test()->partner,
        $order,
        $order->items()->sole()->public_id,
        new DamageReport($quantity, DamageCondition::Torn, 'The fabric tore along the seam on arrival.', parcelExpected: true),
    );

    app(ReceiveDamageParcel::class)->handle(test()->inspector, $damage, test()->warehouse);
    app(StartDamageInspection::class)->handle(test()->inspector, $damage);
    app(RecordDamageInspection::class)->handle(test()->inspector, $damage, new DamageInspection(
        dispositions: [['disposition' => ReturnDisposition::Damaged, 'quantity' => $quantity]],
        cause: DamageCause::PartnerHandling,
        note: 'Seam torn through; not a wear mark.',
        warehouse: test()->warehouse,
    ));

    return $damage->refresh();
}

/**
 * A damage the Partner bears, decided and posted by the right people.
 */
function caseTestDamagePosted(Order $order, ReturnParty $bearer = ReturnParty::Partner, string $loss = '5000.00'): OrderDamage
{
    $damage = caseTestDamageUnderReview($order);

    app(ApproveDamageLiability::class)->handle(
        test()->viewer, $damage, $bearer, Money::fromDecimal($loss, Currency::BDT), 'Decided on the evidence and the inspection.',
    );

    return app(FinalizeDamage::class)->handle(test()->viewer, $damage);
}

/**
 * A one-pixel PNG, so the file's own bytes say what it is.
 */
function caseTestPng(string $name = 'proof.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='),
    );
}

/**
 * A script wearing an image's name and claimed type.
 *
 * Laravel's fake upload reports its type from its *name*, which would make a test
 * of "judged by its bytes" pass for the wrong reason; a real temporary file is
 * read the way the server reads one.
 */
function caseTestDisguisedScript(string $name = 'photo.png'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'case');
    file_put_contents($path, '<?php echo "hi";');

    return new UploadedFile($path, $name, 'image/png', null, true);
}

/**
 * A minimal PDF.
 */
function caseTestPdf(string $name = 'receipt.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
}
