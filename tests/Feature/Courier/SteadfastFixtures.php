<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Courier\Actions\ConfigureSteadfast;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
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

    return Order::factory()->create([
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
    ]);
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
