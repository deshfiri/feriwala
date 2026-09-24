<?php

use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\ExpireUnconfirmedCodOrders;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Confirming a cash-on-delivery order under parallel requests, with real
 * processes (§6.2, §19.1, §43, P6-10).
 *
 * The same correct code submitted by several workers at one instant, and a
 * confirmation racing the sweep that closes the window. However they fall: the
 * order ends in exactly one state, its stock is accounted for once, and there
 * is one status entry and one webhook for what happened.
 *
 * The code lives in the cache, which is per-process in tests, so this file
 * points the cache at Redis — the workers have to see the same code, as they
 * would in production. Everything it writes is removed afterwards.
 */
beforeEach(function () {
    config()->set('database.connections.cod_race', config('database.connections.pgsql'));
    config()->set('database.default', 'cod_race');
    config()->set('cache.default', 'redis');

    $this->ordersFrom = (int) DB::table('orders')->max('id');
    $this->paymentsFrom = (int) DB::table('payments')->max('id');
    $this->reservationsFrom = (int) DB::table('stock_reservations')->max('id');
    $this->movementsFrom = (int) DB::table('stock_movements')->max('id');
    $this->deliveriesFrom = (int) DB::table('webhook_deliveries')->max('id');
    $this->customersFrom = (int) DB::table('website_customers')->max('id');
    $this->auditFrom = (int) DB::table('audit_logs')->max('id');

    $settings = app(SettingsRepository::class);
    $this->createdSettings = [];

    if (! Setting::query()->where('key', CodTerms::ENABLED)->exists()) {
        $settings->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
        $this->createdSettings[] = CodTerms::ENABLED;
    }

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    $this->fee = FeeRule::create([
        'fee_type' => FeeType::WebsiteDelivery->value,
        'currency_code' => 'BDT',
        'amount' => Money::zero(),
        'effective_from' => now()->subDay(),
    ]);

    // An endpoint, so what the shop is told can be counted.
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');

    // Committed rows outlive the test's transaction, so everything here is the
    // test's own and named so, and afterEach takes it all back out again.
    $this->warehouse = Warehouse::create(['code' => 'COD-'.Str::upper(Str::random(6)), 'name' => 'Dhaka', 'is_default' => true]);
    $this->categoriesFrom = (int) DB::table('categories')->max('id');
    $this->product = websiteTestProduct();

    WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00'),
        'published_at' => now(),
    ]);

    $this->item = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id]);
    app(StockLedger::class)->move($this->item, null, StockBucket::Available, 10, StockMovementType::Adjustment);
});

afterEach(function () {
    $orders = DB::table('orders')->where('id', '>', $this->ordersFrom)->pluck('id')->all();
    $accountIds = [$this->account->id];
    $ownerIds = DB::table('business_accounts')->whereIn('id', $accountIds)->pluck('owner_id')->all();

    // The tables refuse edits and deletes by trigger; the guards come off for
    // the cleanup and go straight back on.
    $guards = [
        'orders' => 'orders_never_deleted',
        'order_items' => 'order_items_are_snapshots',
        'order_status_history' => 'order_status_history_no_delete',
        'stock_movements' => 'stock_movements_no_delete',
        'audit_logs' => 'audit_logs_no_delete',
    ];

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    }

    DB::table('order_status_history')->whereIn('order_id', $orders)->delete();
    DB::table('order_items')->whereIn('order_id', $orders)->delete();
    $deliveries = DB::table('webhook_deliveries')->where('id', '>', $this->deliveriesFrom)->pluck('id');

    DB::table('webhook_logs')->whereIn('webhook_delivery_id', $deliveries)->delete();
    DB::table('webhook_deliveries')->whereIn('id', $deliveries)->delete();
    DB::table('orders')->whereIn('id', $orders)->delete();
    DB::table('stock_movements')->where('id', '>', $this->movementsFrom)->delete();
    DB::table('audit_logs')->where('id', '>', $this->auditFrom)->delete();

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }

    DB::table('stock_reservations')->where('id', '>', $this->reservationsFrom)->delete();
    DB::table('payment_allocations')->whereIn('payment_id', DB::table('payments')->where('id', '>', $this->paymentsFrom)->pluck('id'))->delete();
    DB::table('payments')->where('id', '>', $this->paymentsFrom)->delete();
    DB::table('website_customers')->where('id', '>', $this->customersFrom)->delete();
    DB::table('stock_items')->where('id', $this->item->id)->delete();
    DB::table('website_products')->where('website_id', $this->website->id)->delete();
    DB::table('website_webhook_endpoints')->where('website_id', $this->website->id)->delete();
    DB::table('websites')->where('id', $this->website->id)->delete();
    DB::table('fee_rules')->where('id', $this->fee->id)->delete();
    DB::table('settings')->whereIn('key', $this->createdSettings)->delete();
    // Only a draft product may be deleted (P3-3); this one never went to a
    // partner's catalogue outside the test, so it goes back to draft first.
    DB::table('products')->where('id', $this->product->id)->update(['status' => 'draft']);
    DB::table('products')->where('id', $this->product->id)->delete();
    DB::table('categories')->where('id', '>', $this->categoriesFrom)->delete();
    DB::table('warehouses')->where('id', $this->warehouse->id)->delete();
    DB::table('business_accounts')->whereIn('id', $accountIds)->update(['current_user_package_id' => null]);
    DB::table('user_packages')->whereIn('business_account_id', $accountIds)->delete();
    DB::table('business_accounts')->whereIn('id', $accountIds)->delete();
    DB::table('users')->whereIn('id', $ownerIds)->delete();

    config()->set('database.default', 'pgsql');
    config()->set('cache.default', 'array');
});

/**
 * A cash-on-delivery order waiting for its code, and the code itself.
 *
 * @return array{0: Order, 1: string}
 */
function codRaceOrder(): array
{
    // Kept as a poisha-shorthand closure so the call sites below need no
    // change: converted to exact Taka once, here, via bcmath (D26).
    $money = fn (int $minor) => Money::fromDecimal(bcdiv((string) $minor, '100', 2), Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-RACE-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => 2]],
        claimedUnitPrices: [$money(260000)],
        claimedTotals: [
            'subtotal' => $money(520000),
            'discount' => $money(0),
            'shipping' => $money(0),
            'tax' => $money(0),
            'grand_total' => $money(520000),
        ],
        paymentMethod: 'cod',
    ));

    // The code the customer was sent, issued again so the test holds one that
    // works: issuing replaces what was there, exactly as a resend does.
    $code = app(VerificationCodes::class)->issue(SendCodConfirmationCode::PURPOSE, $order->public_id);

    return [$order->refresh(), $code];
}

/**
 * Give this worker its own Redis sockets.
 *
 * The connection objects came across the fork already open, and every holder —
 * the cache repository behind the codes, the lock store behind the
 * confirmation — points at the same one. Closing it here leaves each of them
 * to reconnect on first use, in this process alone.
 */
function codRaceOwnRedis(): void
{
    foreach (array_diff(array_keys(config('database.redis')), ['client', 'options']) as $connection) {
        try {
            app('redis')->connection($connection)->disconnect();
        } catch (Throwable) {
            // Never opened in the first place; nothing to hand back.
        }
    }
}

/**
 * Run each job in its own process, all starting together.
 *
 * @param  array<int, mixed>  $jobs
 * @param  callable(mixed): void  $work
 */
function codRaceFork(array $jobs, callable $work): void
{
    // A socket opened here is inherited by every worker, and several processes
    // writing down one connection is not concurrency, it is corruption. The
    // database connection is dropped before the fork, so each worker opens its
    // own; Redis is dropped inside each worker, because the objects holding it
    // are shared and the parent's socket must survive for the assertions.
    DB::purge('cod_race');

    $startAt = microtime(true) + 0.5;
    $pids = [];

    foreach ($jobs as $job) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
            codRaceOwnRedis();

            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            try {
                $work($job);
            } catch (Throwable) {
                // Being refused is the expected outcome for most workers. What
                // happened is read from the database afterwards, not from here.
            }

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

it('confirms once when the same code arrives from several workers at one instant', function () {
    [$order, $code] = codRaceOrder();

    codRaceFork(range(1, 5), function () use ($order, $code) {
        /** @var Order $fresh */
        $fresh = Order::query()->findOrFail($order->id);

        app(ConfirmCodOrder::class)->handle($fresh, $code);
    });

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Confirmed);

    $reservations = DB::table('stock_reservations')->where('id', '>', $this->reservationsFrom)->get();

    expect($reservations)->toHaveCount(1)
        ->and($reservations->first()->status)->toBe(StockReservationStatus::Committed->value)
        // One move of the units, not five.
        ->and(DB::table('stock_movements')->where('id', '>', $this->movementsFrom)->where('to_bucket', StockBucket::Processing->value)->count())->toBe(1)
        ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('processing'))->toBe(2)
        ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('reserved'))->toBe(0);

    expect(DB::table('order_status_history')->where('order_id', $order->id)->where('new_status', OrderStatus::Confirmed->value)->count())->toBe(1)
        ->and(DB::table('webhook_deliveries')->where('id', '>', $this->deliveriesFrom)->where('event_type', 'order.status_changed')->count())->toBe(1);
});

it('ends in exactly one state when a confirmation races the sweep that closes the window', function () {
    [$order, $code] = codRaceOrder();

    // The window shuts at this instant: both the customer and the sweep have a
    // claim on the order, and only one of them may have it.
    DB::table('payments')->where('id', $order->payment_id)->update(['expires_at' => now()->subSecond()]);
    DB::table('stock_reservations')
        ->where('reference', $order->reference.'-L1')
        ->update(['expires_at' => now()->addSeconds(2)]);

    codRaceFork(['confirm', 'expire'], function (string $job) use ($order, $code) {
        if ($job === 'confirm') {
            /** @var Order $fresh */
            $fresh = Order::query()->findOrFail($order->id);

            app(ConfirmCodOrder::class)->handle($fresh, $code);

            return;
        }

        app(ExpireUnconfirmedCodOrders::class)->handle();
    });

    $order->refresh();

    $reservation = DB::table('stock_reservations')->where('id', '>', $this->reservationsFrom)->sole();

    expect($order->status)->toBeIn([OrderStatus::Confirmed, OrderStatus::Cancelled]);

    if ($order->status === OrderStatus::Confirmed) {
        // Confirmed means the units moved on, and nothing gave them back.
        expect($reservation->status)->toBe(StockReservationStatus::Committed->value)
            ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('processing'))->toBe(2)
            ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('available'))->toBe(8);
    } else {
        // Cancelled means the units came back, and nothing committed them.
        expect($reservation->status)->toBe(StockReservationStatus::Released->value)
            ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('processing'))->toBe(0)
            ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('available'))->toBe(10);
    }

    // One outcome, once: never both entries, never two of either.
    $history = DB::table('order_status_history')->where('order_id', $order->id)->pluck('new_status');

    expect($history->filter(fn (string $status) => $status === OrderStatus::Confirmed->value)->count()
        + $history->filter(fn (string $status) => $status === OrderStatus::Cancelled->value)->count())->toBe(1)
        ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('reserved'))->toBe(0);
});
