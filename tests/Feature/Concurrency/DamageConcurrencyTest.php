<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\FeeRule;
use App\Domain\Billing\Models\Payment;
use App\Domain\Damage\Actions\ApproveDamageLiability;
use App\Domain\Damage\Actions\ChangeDamageResponsibility;
use App\Domain\Damage\Actions\FinalizeDamage;
use App\Domain\Damage\Actions\ReceiveDamageParcel;
use App\Domain\Damage\Actions\RecordDamageInspection;
use App\Domain\Damage\Actions\ReportDamage;
use App\Domain\Damage\Actions\StartDamageInspection;
use App\Domain\Damage\Data\DamageInspection;
use App\Domain\Damage\Data\DamageReport;
use App\Domain\Damage\Enums\DamageCause;
use App\Domain\Damage\Enums\DamageCondition;
use App\Domain\Damage\Enums\DamageStatus;
use App\Domain\Damage\Models\OrderDamage;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockReservations;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnDisposition;
use App\Domain\Order\Enums\ReturnParty;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
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
 * Damage cases under parallel requests, with real processes (D35, §43).
 *
 * The same approved loss finalized by several workers at one instant, the same
 * attribution corrected by several at once, the same report arriving several
 * times together. However they fall, each thing happens once: one debit, one
 * reversal and one re-posting, one case.
 *
 * Everything here is committed, because each worker is its own process with its
 * own connection, and everything it writes is removed afterwards.
 */
beforeEach(function () {
    config()->set('database.connections.damage_race', config('database.connections.pgsql'));
    config()->set('database.default', 'damage_race');

    $this->watermarks = [];

    foreach ([
        'orders', 'payments', 'stock_reservations', 'stock_movements', 'partner_stock_movements',
        'website_customers', 'audit_logs', 'order_damages', 'wallets', 'wallet_transactions', 'ledger_entries',
        'categories', 'users', 'settings', 'roles', 'permissions',
    ] as $table) {
        $this->watermarks[$table] = (int) DB::table($table)->max('id');
    }

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

    $this->warehouse = Warehouse::create(['code' => 'DMG-'.Str::upper(Str::random(6)), 'name' => 'Dhaka', 'is_default' => true]);
    $this->product = websiteTestProduct();

    $selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00'),
        'published_at' => now(),
    ]);

    $this->item = websiteTestOwnsSelection($selection, 10);
    $this->wallet = websiteTestWallet($this->account, '10000.00');

    // One person holding every right a damage needs, for every worker.
    $this->staff = testPlatformStaff(PlatformRole::FulfillmentManager);
    $this->staff->assignRole(PlatformRole::FinanceManager->value);
});

afterEach(function () {
    $orders = DB::table('orders')->where('id', '>', $this->watermarks['orders'])->pluck('id')->all();
    $damages = DB::table('order_damages')->where('id', '>', $this->watermarks['order_damages'])->pluck('id')->all();
    $accountIds = [$this->account->id];
    $userIds = DB::table('users')->where('id', '>', $this->watermarks['users'])->pluck('id')->all();

    // The tables refuse edits and deletes by trigger; the guards come off for
    // the cleanup, only for rows this test wrote, and go straight back on.
    $guards = [
        'orders' => 'orders_never_deleted',
        'order_items' => 'order_items_no_delete',
        'order_status_history' => 'order_status_history_no_delete',
        'stock_movements' => 'stock_movements_no_delete',
        'partner_stock_movements' => 'partner_stock_movements_no_delete',
        'audit_logs' => 'audit_logs_no_delete',
        'order_damages' => 'order_damages_no_delete',
        'order_damage_charges' => 'order_damage_charges_no_delete',
        'order_damage_dispositions' => 'order_damage_dispositions_no_delete',
        'order_damage_dispatches' => 'order_damage_dispatches_no_delete',
        'order_damage_status_history' => 'order_damage_status_history_no_delete',
        'order_damage_responsibility_changes' => 'order_damage_responsibility_changes_no_delete',
        'ledger_entries' => 'ledger_entries_no_delete',
        'wallet_transaction_events' => 'wallet_transaction_events_no_delete',
    ];

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER {$trigger}");
    }

    // A reversal points at the charge it answers, so the answers go first.
    DB::table('order_damage_charges')->whereIn('order_damage_id', $damages)->whereNotNull('reverses_charge_id')->delete();
    DB::table('order_damage_charges')->whereIn('order_damage_id', $damages)->delete();
    DB::table('order_damage_dispatches')->whereIn('order_damage_id', $damages)->delete();
    DB::table('order_damage_dispositions')->whereIn('order_damage_id', $damages)->delete();
    DB::table('order_damage_responsibility_changes')->whereIn('order_damage_id', $damages)->delete();
    DB::table('order_damage_status_history')->whereIn('order_damage_id', $damages)->delete();
    DB::table('order_damages')->whereIn('id', $damages)->delete();

    DB::table('order_status_history')->whereIn('order_id', $orders)->delete();
    DB::table('order_items')->whereIn('order_id', $orders)->delete();
    DB::table('orders')->whereIn('id', $orders)->delete();
    DB::table('stock_movements')->where('id', '>', $this->watermarks['stock_movements'])->delete();
    DB::table('partner_stock_movements')->where('id', '>', $this->watermarks['partner_stock_movements'])->delete();
    DB::table('audit_logs')->where('id', '>', $this->watermarks['audit_logs'])->delete();

    DB::table('wallet_transaction_events')->where('wallet_id', '>', $this->watermarks['wallets'])->delete();
    DB::table('ledger_entries')->where('wallet_id', '>', $this->watermarks['wallets'])->delete();
    DB::table('wallet_transactions')->where('wallet_id', '>', $this->watermarks['wallets'])->delete();
    DB::table('wallets')->where('id', '>', $this->watermarks['wallets'])->delete();

    foreach ($guards as $table => $trigger) {
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER {$trigger}");
    }

    DB::table('stock_reservations')->where('id', '>', $this->watermarks['stock_reservations'])->delete();
    $paymentIds = DB::table('payments')->where('id', '>', $this->watermarks['payments'])->pluck('id');
    DB::table('payment_logs')->whereIn('payment_id', $paymentIds)->delete();
    DB::table('payment_allocations')->whereIn('payment_id', $paymentIds)->delete();
    DB::table('payments')->whereIn('id', $paymentIds)->delete();
    DB::table('website_customers')->where('id', '>', $this->watermarks['website_customers'])->delete();
    DB::table('partner_stock_items')->where('business_account_id', $this->account->id)->delete();
    DB::table('website_products')->where('website_id', $this->website->id)->delete();
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
 * An order for three, paid and delivered, with two of its units reported
 * damaged, received, inspected as damaged and the loss approved on the Partner —
 * the point finance posts it.
 */
function damageRaceApproved(): OrderDamage
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    /** @var Order $order */
    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-DR-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => 3]],
        claimedUnitPrices: [$money('2600.00')],
        claimedTotals: [
            'subtotal' => $money('7800.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('0.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('7800.00'),
        ],
        paymentMethod: 'online',
    ));

    /** @var Payment $payment */
    $payment = $order->payment;
    $payment->transitionTo(PaymentStatus::Initiated)->save();
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'race-'.Str::random(8)])->save();

    foreach ($order->items()->with('stockReservation')->get() as $line) {
        app(StockReservations::class)->commit($line->stockReservation);
    }

    $order->refresh()->moveTo(OrderStatus::Paid, new StatusChange(reason: 'Paid.'), OrderStatusChangeSource::PaymentGateway);

    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test.'), OrderStatusChangeSource::System);
    }

    app(PartnerStock::class)->move(test()->item, StockBucket::Processing, StockBucket::Sold, 3, PartnerStockMovementType::Adjustment);

    $damage = app(ReportDamage::class)->fromDelivered(
        test()->account->owner,
        $order->refresh(),
        $order->items()->sole()->public_id,
        new DamageReport(2, DamageCondition::Torn, 'The fabric tore along the seam on arrival.', parcelExpected: true),
    );

    app(ReceiveDamageParcel::class)->handle(test()->staff, $damage, test()->warehouse);
    app(StartDamageInspection::class)->handle(test()->staff, $damage);
    app(RecordDamageInspection::class)->handle(test()->staff, $damage, new DamageInspection(
        dispositions: [['disposition' => ReturnDisposition::Damaged, 'quantity' => 2]],
        cause: DamageCause::PartnerHandling,
        note: 'Seam torn through; not a wear mark.',
        warehouse: test()->warehouse,
    ));

    return app(ApproveDamageLiability::class)->handle(
        test()->staff, $damage->refresh(), ReturnParty::Partner, Money::fromDecimal('5000.00', Currency::BDT), 'Decided on the evidence.',
    );
}

/**
 * Run each job in its own process, all starting together.
 *
 * @param  array<int, mixed>  $jobs
 * @param  callable(mixed): void  $work
 */
function damageRaceFork(array $jobs, callable $work): void
{
    // A database socket opened here would be shared by every worker; dropped,
    // each worker opens its own and the parent reconnects afterwards.
    DB::purge('damage_race');

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

it('posts one loss, however many workers finalize it', function () {
    $damage = damageRaceApproved();

    damageRaceFork(range(1, 5), function () use ($damage) {
        /** @var OrderDamage $fresh */
        $fresh = OrderDamage::query()->findOrFail($damage->id);
        /** @var User $staff */
        $staff = User::query()->findOrFail(test()->staff->id);

        app(FinalizeDamage::class)->handle($staff, $fresh);
    });

    $damage->refresh();

    expect($damage->status)->toBe(DamageStatus::Finalized)
        ->and(DB::table('order_damage_charges')->where('order_damage_id', $damage->id)->count())->toBe(1)
        ->and(DB::table('wallet_transactions')->where('wallet_id', test()->wallet->id)->where('type', 'damage_loss_debit')->count())->toBe(1)
        ->and(test()->wallet->refresh()->total->toDecimal())->toBe('5000.00')
        ->and(DB::table('order_damage_status_history')->where('order_damage_id', $damage->id)->where('new_status', 'finalized')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'damage.finalized')->where('auditable_id', $damage->id)->count())->toBe(1);
});

it('makes one compensating pair, however many workers correct the same attribution', function () {
    $damage = damageRaceApproved();
    app(FinalizeDamage::class)->handle($this->staff, $damage);

    damageRaceFork(range(1, 5), function () use ($damage) {
        /** @var OrderDamage $fresh */
        $fresh = OrderDamage::query()->findOrFail($damage->id);
        /** @var User $staff */
        $staff = User::query()->findOrFail(test()->staff->id);

        app(ChangeDamageResponsibility::class)->handle($staff, $fresh, ReturnParty::Banij, 'Packed badly at the warehouse: the fault is ours.');
    });

    $damage->refresh();

    // The debit, its reversal, and the one re-posting to Banij — never a second pair.
    expect($damage->responsible_party)->toBe(ReturnParty::Banij)
        ->and(DB::table('order_damage_charges')->where('order_damage_id', $damage->id)->count())->toBe(3)
        ->and(DB::table('order_damage_charges')->where('order_damage_id', $damage->id)->where('entry_type', 'reversal')->count())->toBe(1)
        ->and(DB::table('order_damage_charges')->where('order_damage_id', $damage->id)->where('entry_type', 'charge')->where('bearer', 'banij')->count())->toBe(1)
        ->and(DB::table('order_damage_responsibility_changes')->where('order_damage_id', $damage->id)->count())->toBe(2)
        ->and(test()->wallet->refresh()->total->toDecimal())->toBe('10000.00')
        ->and(DB::table('audit_logs')->where('action', 'damage.responsibility_changed')->where('auditable_id', $damage->id)->count())->toBe(1);
});

it('opens one case, however many workers report the same damage', function () {
    $damage = damageRaceApproved();
    $order = Order::query()->findOrFail($damage->order_id);
    $line = $order->items()->sole()->public_id;
    $before = DB::table('order_damages')->count();

    // One unit is still left to report: five workers send the same report at once.
    damageRaceFork(range(1, 5), function () use ($order, $line) {
        /** @var Order $fresh */
        $fresh = Order::query()->findOrFail($order->id);
        /** @var User $owner */
        $owner = User::query()->findOrFail(test()->account->owner_id);

        app(ReportDamage::class)->fromDelivered($owner, $fresh, $line, new DamageReport(
            quantity: 1,
            condition: DamageCondition::Torn,
            description: 'The last panjabi has the same tear in the same place.',
            idempotencyKey: 'same-report-from-five-workers',
        ));
    });

    expect(DB::table('order_damages')->count())->toBe($before + 1)
        ->and(DB::table('order_damages')->where('idempotency_key', 'same-report-from-five-workers')->count())->toBe(1);
});
