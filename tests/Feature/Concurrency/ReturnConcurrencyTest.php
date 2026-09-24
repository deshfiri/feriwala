<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\DecideOrderReturn;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReceiveReturnedItems;
use App\Domain\Order\Actions\RefundOrderReturn;
use App\Domain\Order\Actions\RequestOrderReturn;
use App\Domain\Order\Data\ReturnedLine;
use App\Domain\Order\Data\ReturnSubmission;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnReason;
use App\Domain\Order\Enums\ReturnRefundState;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/*
 * Returns under parallel requests, with real processes (§19.1, §26.3, §43,
 * P6-12).
 *
 * The same returned goods counted in by several workers at one instant, and the
 * same return's refund started by several at once. However they fall: the
 * goods go back into stock once, one refund is opened for them, and the record
 * says each thing happened once.
 *
 * Everything here is committed, because each worker is its own process with its
 * own connection, and everything it writes is removed afterwards.
 */
beforeEach(function () {
    config()->set('database.connections.return_race', config('database.connections.pgsql'));
    config()->set('database.default', 'return_race');

    $this->watermarks = [];

    foreach ([
        'orders', 'payments', 'stock_reservations', 'stock_movements', 'webhook_deliveries',
        'website_customers', 'audit_logs', 'order_returns', 'order_return_items', 'refund_requests',
        'categories', 'users', 'settings', 'roles', 'permissions',
    ] as $table) {
        $this->watermarks[$table] = (int) DB::table($table)->max('id');
    }

    // Seeded here, committed, and taken back out afterwards — only what this
    // test added, so a schema that already held roles keeps them.
    $this->seed(RolesAndPermissionsSeeder::class);

    $settings = app(SettingsRepository::class);

    foreach ([
        ['payment.sslcommerz.mode', 'sandbox', false],
        ['payment.sslcommerz.sandbox.store_id', 'store', true],
        ['payment.sslcommerz.sandbox.store_password', 'pass', true],
    ] as [$key, $value, $encrypted]) {
        if (! Setting::query()->where('key', $key)->exists()) {
            $settings->define($key, 'payment', SettingType::String, $value, isEncrypted: $encrypted);
        }
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

    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');

    $this->warehouse = Warehouse::create(['code' => 'RET-'.Str::upper(Str::random(6)), 'name' => 'Dhaka', 'is_default' => true]);
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

    // One person holding every right a return needs, for every worker.
    $this->staff = testPlatformStaff(PlatformRole::OrderManager);
    $this->staff->assignRole(PlatformRole::InventoryManager->value);
});

afterEach(function () {
    $orders = DB::table('orders')->where('id', '>', $this->watermarks['orders'])->pluck('id')->all();
    $returns = DB::table('order_returns')->where('id', '>', $this->watermarks['order_returns'])->pluck('id')->all();
    $accountIds = [$this->account->id];
    $userIds = DB::table('users')->where('id', '>', $this->watermarks['users'])->pluck('id')->all();

    // The tables refuse edits and deletes by trigger; the guards come off for
    // the cleanup, only for rows this test wrote, and go straight back on.
    $guards = [
        'orders' => 'orders_never_deleted',
        'order_items' => 'order_items_are_snapshots',
        'order_status_history' => 'order_status_history_no_delete',
        'stock_movements' => 'stock_movements_no_delete',
        'audit_logs' => 'audit_logs_no_delete',
        'order_returns' => 'order_returns_no_delete',
        'order_return_items' => 'order_return_items_no_delete',
        'order_return_status_history' => 'order_return_status_history_no_delete',
        'refund_requests' => 'refund_requests_no_delete',
    ];

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    }

    DB::table('order_return_status_history')->whereIn('order_return_id', $returns)->delete();
    DB::table('order_return_items')->whereIn('order_return_id', $returns)->delete();
    DB::table('order_returns')->whereIn('id', $returns)->delete();
    DB::table('refund_requests')->where('id', '>', $this->watermarks['refund_requests'])->delete();
    DB::table('order_status_history')->whereIn('order_id', $orders)->delete();
    DB::table('order_items')->whereIn('order_id', $orders)->delete();

    $deliveries = DB::table('webhook_deliveries')->where('id', '>', $this->watermarks['webhook_deliveries'])->pluck('id');
    DB::table('webhook_logs')->whereIn('webhook_delivery_id', $deliveries)->delete();
    DB::table('webhook_deliveries')->whereIn('id', $deliveries)->delete();

    DB::table('orders')->whereIn('id', $orders)->delete();
    DB::table('stock_movements')->where('id', '>', $this->watermarks['stock_movements'])->delete();
    DB::table('audit_logs')->where('id', '>', $this->watermarks['audit_logs'])->delete();

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }

    DB::table('stock_reservations')->where('id', '>', $this->watermarks['stock_reservations'])->delete();
    DB::table('payment_logs')->whereIn('payment_id', DB::table('payments')->where('id', '>', $this->watermarks['payments'])->pluck('id'))->delete();
    DB::table('payment_allocations')->whereIn('payment_id', DB::table('payments')->where('id', '>', $this->watermarks['payments'])->pluck('id'))->delete();
    DB::table('payments')->where('id', '>', $this->watermarks['payments'])->delete();
    DB::table('website_customers')->where('id', '>', $this->watermarks['website_customers'])->delete();
    DB::table('stock_items')->where('id', $this->item->id)->delete();
    DB::table('website_products')->where('website_id', $this->website->id)->delete();
    DB::table('website_webhook_endpoints')->where('website_id', $this->website->id)->delete();
    DB::table('websites')->where('id', $this->website->id)->delete();
    DB::table('fee_rules')->where('id', $this->fee->id)->delete();
    DB::table('settings')->where('id', '>', $this->watermarks['settings'])->delete();

    // Only a draft product may be deleted (P3-3); this one never went further
    // than the test, so it goes back to draft first.
    DB::table('products')->where('id', $this->product->id)->update(['status' => 'draft']);
    DB::table('products')->where('id', $this->product->id)->delete();
    DB::table('categories')->where('id', '>', $this->watermarks['categories'])->delete();
    DB::table('warehouses')->where('id', $this->warehouse->id)->delete();
    DB::table('business_accounts')->whereIn('id', $accountIds)->update(['current_user_package_id' => null]);
    DB::table('user_packages')->whereIn('business_account_id', $accountIds)->delete();
    DB::table('business_accounts')->whereIn('id', $accountIds)->delete();
    DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
    DB::table('model_has_permissions')->whereIn('model_id', $userIds)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();

    $roles = DB::table('roles')->where('id', '>', $this->watermarks['roles'])->pluck('id');
    $permissions = DB::table('permissions')->where('id', '>', $this->watermarks['permissions'])->pluck('id');
    DB::table('role_has_permissions')->whereIn('role_id', $roles)->orWhereIn('permission_id', $permissions)->delete();
    DB::table('roles')->whereIn('id', $roles)->delete();
    DB::table('permissions')->whereIn('id', $permissions)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    config()->set('database.default', 'pgsql');
});

/**
 * An order for three, paid, delivered, and two of them asked back and approved
 * — the point a warehouse starts counting.
 */
function returnRaceApprovedReturn(): OrderReturn
{
    // Kept as a poisha-shorthand closure so the call sites below need no
    // change: converted to exact Taka once, here, via bcmath (D26).
    $money = fn (int $minor) => Money::fromDecimal(bcdiv((string) $minor, '100', 2), Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    /** @var Order $order */
    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-RR-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => 3]],
        claimedUnitPrices: [$money(260000)],
        claimedTotals: [
            'subtotal' => $money(780000),
            'discount' => $money(0),
            'shipping' => $money(0),
            'tax' => $money(0),
            'grand_total' => $money(780000),
        ],
        paymentMethod: 'online',
    ));

    // What settlement does: the money arrives and the stock is committed.
    /** @var Payment $payment */
    $payment = $order->payment;
    $payment->transitionTo(PaymentStatus::Initiated)->save();
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'race-'.Str::random(8)])->save();

    foreach ($order->items()->with('stockReservation')->get() as $line) {
        app(StockReservations::class)->commit($line->stockReservation);
    }

    $order->refresh()->moveTo(OrderStatus::Paid, new StatusChange(reason: 'Paid.'), OrderStatusChangeSource::PaymentGateway);

    // What fulfilment will do.
    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test, as fulfilment will.'), OrderStatusChangeSource::System);
    }

    app(StockLedger::class)->move(test()->item, StockBucket::Processing, StockBucket::Sold, 3, StockMovementType::Adjustment);

    [$return] = app(RequestOrderReturn::class)->handle($order->refresh(), new ReturnSubmission(
        reason: ReturnReason::NotAsDescribed,
        lines: [['sku' => test()->product->sku, 'quantity' => 2]],
    ), OrderStatusChangeSource::Storefront);

    app(DecideOrderReturn::class)->approve(test()->staff, $return, [], 'Both are as the customer describes them.');

    return $return->refresh();
}

/**
 * Run each job in its own process, all starting together.
 *
 * @param  array<int, mixed>  $jobs
 * @param  callable(mixed): void  $work
 */
function returnRaceFork(array $jobs, callable $work): void
{
    // A database socket opened here would be shared by every worker; dropped,
    // each worker opens its own and the parent reconnects afterwards.
    DB::purge('return_race');

    $startAt = microtime(true) + 0.5;
    $pids = [];

    foreach ($jobs as $job) {
        $pid = pcntl_fork();

        expect($pid)->not->toBe(-1);

        if ($pid === 0) {
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

it('puts the same returned goods back into stock once, however many workers count them in', function () {
    $return = returnRaceApprovedReturn();
    $line = $return->items()->sole()->public_id;
    $sold = (int) DB::table('stock_items')->where('id', $this->item->id)->value('sold');

    returnRaceFork(range(1, 5), function () use ($return, $line) {
        /** @var OrderReturn $fresh */
        $fresh = OrderReturn::query()->findOrFail($return->id);
        /** @var User $staff */
        $staff = User::query()->findOrFail(test()->staff->id);
        /** @var Warehouse $warehouse */
        $warehouse = Warehouse::query()->findOrFail(test()->warehouse->id);

        app(ReceiveReturnedItems::class)->handle($staff, $fresh, [
            new ReturnedLine($line, 2, ReturnDisposition::Restock),
        ], $warehouse);
    });

    $return->refresh();

    expect($return->status)->toBe(ReturnStatus::Received);

    $restorations = DB::table('stock_movements')
        ->where('id', '>', $this->watermarks['stock_movements'])
        ->where('source_type', 'order_return')
        ->get();

    // One movement, two units, out of sold and onto the shelf — not five.
    expect($restorations)->toHaveCount(1)
        ->and($restorations->first()->quantity)->toBe(2)
        ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('sold'))->toBe($sold - 2)
        ->and((int) DB::table('stock_items')->where('id', $this->item->id)->value('available'))->toBe(9)
        ->and($return->items()->sole()->stock_movement_id)->toBe($restorations->first()->id);

    expect(DB::table('order_return_status_history')->where('order_return_id', $return->id)->where('new_status', ReturnStatus::Received->value)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'order_return.received')->where('auditable_id', $return->id)->count())->toBe(1);
});

it('opens one refund for a return, however many workers start it', function () {
    $return = returnRaceApprovedReturn();

    app(ReceiveReturnedItems::class)->handle($this->staff, $return, [
        new ReturnedLine($return->items()->sole()->public_id, 2, ReturnDisposition::Restock),
    ], $this->warehouse);

    returnRaceFork(range(1, 5), function () use ($return) {
        /** @var OrderReturn $fresh */
        $fresh = OrderReturn::query()->findOrFail($return->id);
        /** @var User $staff */
        $staff = User::query()->findOrFail(test()->staff->id);

        app(RefundOrderReturn::class)->handle($staff, $fresh);
    });

    $return->refresh();

    $refunds = DB::table('refund_requests')->where('id', '>', $this->watermarks['refund_requests'])->get();

    // One request for 5,200 taka — two of the three — never two, never 10,400.
    expect($refunds)->toHaveCount(1)
        ->and((string) $refunds->first()->amount)->toBe('5200.00')
        ->and($return->refund_state)->toBe(ReturnRefundState::Pending)
        ->and($return->refund_request_id)->toBe($refunds->first()->id)
        ->and(DB::table('audit_logs')->where('action', 'order_return.refund_started')->where('auditable_id', $return->id)->count())->toBe(1);

    // Nothing moved money: the request waits for its decision (D17).
    expect(RefundRequest::query()->findOrFail($refunds->first()->id)->status->value)->toBe('requested')
        ->and(Payment::query()->findOrFail($return->order->payment_id)->status)->toBe(PaymentStatus::Paid);
});
