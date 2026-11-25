<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\AllocationSourceType;
use App\Domain\Order\Enums\AllocationStatus;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderItemAllocation;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/*
 * Shared building blocks for the Steadfast status tests. Not a test file: Pest
 * only runs *Test.php. Names are prefixed because Pest's helpers share one
 * global namespace.
 */

/**
 * Give a Store Order one line held in one Banij warehouse, by an active
 * warehouse allocation: a parcel is booked only once its stock resolves to
 * exactly one warehouse.
 */
function steadfastFixtureWarehouseLine(Order $order, ?Warehouse $warehouse = null, int $quantity = 2): Order
{
    $warehouse ??= Warehouse::create(['code' => 'SF-'.Str::upper(Str::random(5)), 'name' => 'Banij Tejgaon Hub', 'is_active' => true]);
    $product = websiteTestProduct();
    $unit = Money::fromDecimal('625.00', Currency::BDT);

    $line = OrderItem::create([
        'order_id' => $order->id,
        'line_number' => OrderItem::query()->where('order_id', $order->id)->count() + 1,
        'product_id' => $product->id,
        'sku' => $product->sku,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'currency_code' => 'BDT',
        'unit_price' => $unit,
        'line_subtotal' => $unit->multipliedBy($quantity),
        'line_total' => $unit->multipliedBy($quantity),
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

    return $order;
}

function steadfastFixtureEnable(): void
{
    app(ConfigureSteadfast::class)->handle(
        testPlatformStaff(PlatformRole::CourierManager),
        true,
        null,
        ['api_key' => 'fixture-api-key', 'secret_key' => 'fixture-secret-key'],
    );
}

/**
 * A dispatched Store Order, ready to have a courier assigned.
 */
function steadfastFixtureOrder(): Order
{
    $account = testBusinessAccount(AccountStatus::Active);
    $site = Website::factory()->forAccount($account)->active()->create();
    $customer = WebsiteCustomer::create([
        'website_id' => $site->id,
        'mobile' => '+88017'.random_int(10000000, 99999999),
        'name' => 'Rahim Uddin',
    ]);

    return steadfastFixtureWarehouseLine(Order::factory()->create([
        'source' => OrderSource::Website,
        'status' => OrderStatus::Shipped,
        'business_account_id' => $account->id,
        'website_id' => $site->id,
        'website_customer_id' => $customer->id,
        'storefront_order_reference' => 'SF-'.Str::upper(Str::random(8)),
        'placed_by' => null,
        'cart_id' => null,
        'customer' => ['name' => 'Rahim Uddin', 'mobile' => '+8801712345678'],
        'shipping_address' => ['line1' => 'House 12, Road 5', 'city' => 'Dhaka'],
    ]));
}

/**
 * A Steadfast shipment as booking leaves it: assigned, `in_review` at Steadfast.
 *
 * @param  array<string, mixed>  $attributes
 */
function steadfastFixtureShipment(?Order $order = null, array $attributes = []): Shipment
{
    $order ??= steadfastFixtureOrder();

    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $order,
        CourierProviderCode::Steadfast,
        Money::fromDecimal('80.00', Currency::BDT),
        [[]],
        testPlatformStaff(PlatformRole::CourierManager),
        '15BAEB8A',
        Money::fromDecimal('1000.00', Currency::BDT),
    );

    $shipment->forceFill([
        'external_id' => (string) random_int(1000000, 9999999),
        'provider_status' => 'in_review',
        'provider_status_at' => now(),
        'provider_synced_at' => now()->subHour(),
        ...$attributes,
    ])->save();

    return $shipment->refresh();
}
