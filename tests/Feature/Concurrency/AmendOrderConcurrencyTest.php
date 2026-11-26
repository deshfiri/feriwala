<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AmendOrder;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\OrderAmendment;
use App\Domain\Order\Data\OrderDetailsRevision;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderRevision;
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
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Two staff saving the Order Details editor at once, for real (D38, amended
 * 2026-11-26): forked workers on a connection whose writes commit, both
 * amending the same order from the same revision. One revision applies; the
 * other is refused as stale. Orders, lines, reservations and revisions are
 * immutable fixture data and are left behind, as in the other race tests.
 */
beforeEach(function () {
    config()->set('database.connections.amend_race', config('database.connections.pgsql'));
    config()->set('database.default', 'amend_race');
    $this->seed(RolesAndPermissionsSeeder::class);

    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $this->product = websiteTestProduct();
    $warehouse = Warehouse::create(['code' => 'AR-'.Str::upper(Str::random(4)), 'name' => 'Dhaka', 'is_default' => true]);
    $this->stock = StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'available' => 50]);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $account = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
    $account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->website = Website::factory()->forAccount($account)->active()->create(['sms_verification_enabled' => false]);
    WebsiteProduct::create([
        'website_id' => $this->website->id, 'business_account_id' => $account->id, 'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published, 'sync_status' => WebsiteSyncStatus::Pending, 'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT), 'published_at' => now(),
    ]);

    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    [$order] = app(PlaceWebsiteOrder::class)->handle($this->website, new WebsiteOrderSubmission(
        deliveryLocation: deliveryTestLocation(), reference: 'SF-AR-'.Str::upper(Str::random(6)), idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Race customer', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address, billingAddress: $address,
        items: [['sku' => $this->product->sku, 'quantity' => 2]], claimedUnitPrices: [$money('2600.00')],
        claimedTotals: ['subtotal' => $money('5200.00'), 'discount' => $money('0.00'), 'shipping' => $money('0.00'), 'tax' => $money('0.00'), 'grand_total' => $money('5200.00')],
        paymentMethod: 'cod',
    ));
    $this->order = $order->refresh();
});

function amendRace(int $count, Closure $work): void
{
    DB::purge('amend_race');
    $startAt = microtime(true) + 0.3;
    $pids = [];

    for ($worker = 0; $worker < $count; $worker++) {
        $pid = pcntl_fork();
        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            // The order lock lives in Redis: each worker needs its own socket,
            // not the parent's, inherited across the fork.
            foreach (array_diff(array_keys(config('database.redis')), ['client', 'options']) as $connection) {
                try {
                    app('redis')->connection($connection)->disconnect();
                } catch (Throwable) {
                    // Never opened; nothing to drop.
                }
            }

            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($worker);
            } catch (Throwable) {
                // The loser is refused; what happened is read back afterwards.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

function amendRaceRequest(Order $order, int $quantity, string $key): OrderAmendment
{
    return new OrderAmendment(
        details: new OrderDetailsRevision([], [], [], null, false, 'Two staff changed the quantity at once.', null, 0, $key),
        lines: [['id' => $order->items()->first()->public_id, 'sku' => null, 'quantity' => $quantity, 'unit_price' => null]],
        discount: null,
        delivery: null,
    );
}

it('applies exactly one of two amendments made from the same revision', function () {
    $orderId = $this->order->id;
    $staffId = $this->staff->id;

    amendRace(2, function (int $worker) use ($orderId, $staffId) {
        $order = Order::query()->findOrFail($orderId);
        app(AmendOrder::class)->handle(User::query()->findOrFail($staffId), $order, amendRaceRequest($order, $worker === 0 ? 3 : 4, 'race-'.$worker));
    });

    $order = Order::query()->findOrFail($orderId);
    $revisions = OrderRevision::query()->where('order_id', $orderId)->get();
    $quantity = $order->items()->sole()->quantity;
    $stock = StockItem::query()->findOrFail($this->stock->id);

    expect($revisions)->toHaveCount(1)
        ->and($order->revision_number)->toBe(1)
        ->and($order->total->toDecimal())->toBe(Money::fromDecimal('2600.00', Currency::BDT)->multipliedBy($quantity)->toDecimal())
        ->and($order->payment->amount->equals($order->total))->toBeTrue()
        // Every unit is accounted for: what is held for the order and what is free.
        ->and($stock->available + $stock->reserved + $stock->processing)->toBe(50)
        ->and($stock->reserved + $stock->processing)->toBe($quantity);
});

it('applies the same request once when it races itself', function () {
    $orderId = $this->order->id;
    $staffId = $this->staff->id;

    amendRace(3, function () use ($orderId, $staffId) {
        $order = Order::query()->findOrFail($orderId);
        app(AmendOrder::class)->handle(User::query()->findOrFail($staffId), $order, amendRaceRequest($order, 3, 'same-key'));
    });

    expect(OrderRevision::query()->where('order_id', $orderId)->count())->toBe(1)
        ->and(Order::query()->findOrFail($orderId)->total->toDecimal())->toBe('7800.00');
});
