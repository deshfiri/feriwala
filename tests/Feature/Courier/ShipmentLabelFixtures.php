<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Courier\Actions\BookSteadfastShipment;
use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\CourierProvider;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Enums\OrderCourierStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Shared building blocks for the shipping-label tests. Not a test file: Pest
 * only runs *Test.php. Every helper is prefixed because Pest's helpers share
 * one global namespace. Nothing here reaches Steadfast: every call is faked.
 */

const SHIPMENT_LABEL_STEADFAST = 'https://portal.steadfast.com.bd/api/v1';

function shipmentLabelEnableSteadfast(): void
{
    app(ConfigureSteadfast::class)->handle(
        testPlatformStaff(PlatformRole::CourierManager),
        true,
        null,
        ['api_key' => 'label-api-key', 'secret_key' => 'label-secret-key'],
    );
}

/**
 * A Banij warehouse approved for labels, with complete return details.
 *
 * @param  array<string, mixed>  $overrides
 */
function shipmentLabelWarehouse(array $overrides = []): Warehouse
{
    return Warehouse::create([
        'code' => 'WH-'.Str::upper(Str::random(5)),
        'name' => 'Banij Tejgaon Hub',
        'address' => 'Internal stock note — not for labels',
        'is_active' => true,
        'courier_contact_name' => 'Karim Dispatch',
        'courier_phone' => '01800000001',
        'return_address_line' => '12 Tejgaon Industrial Area',
        'return_area' => 'Tejgaon',
        'return_district' => 'Dhaka',
        'return_postcode' => '1208',
        'usable_for_courier_labels' => true,
        ...$overrides,
    ]);
}

/**
 * A Storefront with its own branding and a contact address the label must
 * never print.
 *
 * @param  array<string, mixed>  $overrides
 */
function shipmentLabelStorefront(?BusinessAccount $account = null, array $overrides = []): Website
{
    $account ??= testBusinessAccount(AccountStatus::Active);

    return Website::factory()->forAccount($account)->active()->create([
        'name' => 'Rupkotha Fashion',
        'contact_phone' => '01911111111',
        'contact_address' => 'STOREFRONT-CONTACT-ADDRESS 99 Gulshan Avenue',
        'contact_email' => 'store@example.test',
        'primary_color' => '#c2410c',
        ...$overrides,
    ]);
}

/**
 * A dispatched Storefront order, cash on delivery, with a note and an email
 * the label must never show.
 *
 * @param  array<string, mixed>  $overrides
 */
function shipmentLabelOrder(?Website $site = null, array $overrides = []): Order
{
    $site ??= shipmentLabelStorefront();

    $customer = WebsiteCustomer::create([
        'website_id' => $site->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Rahim Uddin',
    ]);

    return Order::factory()->create([
        'source' => OrderSource::Website,
        'status' => OrderStatus::Shipped,
        'business_account_id' => $site->business_account_id,
        'website_id' => $site->id,
        'website_customer_id' => $customer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(8)),
        'placed_by' => null,
        'cart_id' => null,
        'customer' => ['name' => 'Rahim Uddin', 'mobile' => '+8801712345678', 'email' => 'rahim.private@example.test'],
        'shipping_address' => [
            'line1' => 'House 12, Road 5, Block C',
            'line2' => null,
            'area' => 'Mirpur 10',
            'city' => 'Dhaka',
            'district' => 'Dhaka',
            'postcode' => '1216',
            'country' => 'BD',
        ],
        'customer_note' => 'Call before delivery',
        'subtotal' => Money::fromDecimal('1250.00', Currency::BDT),
        'total' => Money::fromDecimal('1250.00', Currency::BDT),
        ...$overrides,
    ]);
}

/**
 * One order line held in `$warehouse` by an active warehouse allocation --
 * the authoritative record of where it ships from.
 */
function shipmentLabelLine(Order $order, Warehouse $warehouse, int $quantity = 2): OrderItem
{
    $product = websiteTestProduct();

    $line = OrderItem::create([
        'order_id' => $order->id,
        'line_number' => OrderItem::query()->where('order_id', $order->id)->count() + 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'currency_code' => 'BDT',
        'unit_price' => Money::fromDecimal('625.00', Currency::BDT),
        'line_subtotal' => Money::fromDecimal('1250.00', Currency::BDT),
        'line_total' => Money::fromDecimal('1250.00', Currency::BDT),
        'created_at' => now(),
    ]);

    OrderItemAllocation::create([
        'order_id' => $order->id,
        'order_item_id' => $line->id,
        'source_type' => AllocationSourceType::Warehouse,
        'warehouse_id' => $warehouse->id,
        'quantity' => $quantity,
        'unit_cost' => Money::fromDecimal('400.00', Currency::BDT),
        'platform_rate' => Money::fromDecimal('500.00', Currency::BDT),
        'expected_margin' => Money::fromDecimal(bcmul('100.00', (string) $quantity, 2), Currency::BDT),
        'currency_code' => 'BDT',
        'status' => AllocationStatus::Active,
        'allocated_at' => now(),
        'idempotency_key' => 'alloc:'.Str::ulid(),
    ]);

    return $line;
}

/**
 * A Steadfast create_order reply.
 *
 * @return array<string, mixed>
 */
function shipmentLabelSteadfastCreated(Order $order, string $consignmentId = '1424107', string $trackingCode = '15BAEB8A'): array
{
    return [
        'status' => 200,
        'message' => 'Consignment has been created successfully.',
        'consignment' => [
            'consignment_id' => (int) $consignmentId,
            'invoice' => $order->reference,
            'tracking_code' => $trackingCode,
            'recipient_name' => 'Rahim Uddin',
            'status' => 'in_review',
        ],
    ];
}

/**
 * Book the order with (faked) Steadfast and return its shipment.
 */
function shipmentLabelBook(Order $order, string $consignmentId = '1424107', string $trackingCode = '15BAEB8A'): ?Shipment
{
    // Only create_order is answered here, so a test can fake a later status lookup itself.
    Http::fake([SHIPMENT_LABEL_STEADFAST.'/create_order' => Http::response(shipmentLabelSteadfastCreated($order, $consignmentId, $trackingCode))]);

    app(BookSteadfastShipment::class)->handle($order, testPlatformStaff(PlatformRole::CourierManager));

    return Shipment::query()->where('order_id', $order->id)->latest('id')->first();
}

/**
 * A manual-courier shipment raised through the shared action.
 */
function shipmentLabelManual(Order $order, ?string $cod = '1250.00', ?string $tracking = 'MAN-0001'): Shipment
{
    return app(CreateShipmentFromAllocations::class)->handle(
        $order,
        CourierProviderCode::Manual,
        Money::fromDecimal('80.00', Currency::BDT),
        [['weight' => '1.0']],
        testPlatformStaff(PlatformRole::CourierManager),
        $tracking,
        $cod === null ? null : Money::fromDecimal($cod, Currency::BDT),
    );
}

/**
 * A further parcel of an order, written straight to the table.
 *
 * Banij cannot raise a second live shipment for one order yet -- the order's
 * own courier status follows a single parcel -- so this stands in for the
 * per-warehouse split that is awaiting approval, to prove what the label does
 * once parcels are split. It is not how a shipment is ever created in code.
 */
function shipmentLabelExtraParcel(Order $order, Warehouse $warehouse, string $tracking, string $cod = '0.00'): Shipment
{
    $shipment = Shipment::create([
        'order_id' => $order->id,
        'courier_provider_id' => CourierProvider::query()->where('code', CourierProviderCode::Manual->value)->value('id'),
        'warehouse_id' => $warehouse->id,
        'status' => OrderCourierStatus::Assigned,
        'tracking_number' => $tracking,
        'currency_code' => 'BDT',
        'delivery_charge' => Money::fromDecimal('80.00', Currency::BDT),
        'cod_amount' => Money::fromDecimal($cod, Currency::BDT),
    ]);

    $shipment->packages()->create(['weight' => '1.0']);

    return $shipment;
}

/**
 * How many create_order calls went out.
 */
function shipmentLabelBookingCalls(): int
{
    return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'create_order'))->count();
}
