<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\GatewayOwnership;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\PartnerGatewayConfiguration;
use App\Domain\Billing\Models\PartnerGatewayConfigurationVersion;
use App\Domain\Courier\Actions\CreateShipmentFromAllocations;
use App\Domain\Courier\Enums\CourierProviderCode;
use App\Domain\Courier\Jobs\IssueShipmentLabelJob;
use App\Domain\Courier\Models\Shipment;
use App\Domain\Courier\Models\ShipmentLabel;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\EvaluateOrderProceedsEligibility;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\ReconcileStorefrontHistory;
use App\Domain\Order\Actions\SettleStorefrontProfit;
use App\Domain\Order\Data\StorefrontReconciliationOptions;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\PartnerStorefrontProfit;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Actions\OpenWallet;
use App\Domain\Wallet\Actions\VerifyLedgerIntegrity;
use App\Domain\Wallet\Data\PostingContext;
use App\Domain\Wallet\Enums\BalanceType;
use App\Domain\Wallet\Enums\LedgerTransactionType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
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
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Courier/ShipmentLabelFixtures.php';

/*
 * The one legacy reconciliation (D40): historical Storefront orders brought
 * into line — courier, delivery, collection, account type, cost, profit and
 * label — read-only by default, and with no second effect however often it runs.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    Http::preventStrayRequests();
    Http::fake(['*sslcommerz*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1'])]);

    $this->product = websiteTestProduct();
    $warehouse = Warehouse::create(['code' => 'REC', 'name' => 'Dhaka', 'is_default' => true]);
    StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'available' => 50]);
    $this->staff = testPlatformStaff(PlatformRole::OrderManager);
});

function reconcileOptions(bool $apply = true, array $extra = []): StorefrontReconciliationOptions
{
    return new StorefrontReconciliationOptions(apply: $apply, courierApi: $extra['courierApi'] ?? false, order: $extra['order'] ?? null, resume: $extra['resume'] ?? null, limit: $extra['limit'] ?? null, retryBaseMilliseconds: 0, pauseMilliseconds: 0);
}

/** @return array<string, mixed> */
function reconcileRun(bool $apply = true, array $extra = []): array
{
    return app(ReconcileStorefrontHistory::class)->run(reconcileOptions($apply, $extra));
}

/** @return array{0: BusinessAccount, 1: Website} */
function reconcilePartner(AccountType $type, string $price = '2600.00'): array
{
    $account = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
    $account->forceFill(['account_type' => $type])->save();
    $website = Website::factory()->forAccount($account)->active()->create(['sms_verification_enabled' => false]);
    WebsiteProduct::create([
        'website_id' => $website->id, 'business_account_id' => $account->id, 'product_id' => test()->product->id,
        'status' => WebsiteProductStatus::Published, 'sync_status' => WebsiteSyncStatus::Pending, 'currency_code' => 'BDT',
        'price' => Money::fromDecimal($price, Currency::BDT), 'published_at' => now(),
    ]);

    return [$account->refresh(), $website];
}

function reconcilePurchase(BusinessAccount $account, int $quantity, string $unit, string $paidAt = '2026-10-01'): void
{
    $order = Order::factory()->create(['business_account_id' => $account->id, 'paid_at' => CarbonImmutable::parse($paidAt)]);
    $price = Money::fromDecimal($unit, Currency::BDT);
    $order->items()->create([
        'line_number' => 1, 'product_id' => test()->product->id, 'sku' => test()->product->sku, 'product_name' => 'x',
        'quantity' => $quantity, 'currency_code' => 'BDT', 'unit_price' => $price,
        'line_subtotal' => $price->multipliedBy($quantity), 'line_total' => $price->multipliedBy($quantity),
    ]);
    app(ReceivePartnerPurchase::class)->handle($order->refresh());
}

function reconcilePlace(Website $website, int $quantity, string $unit, string $method = 'cod'): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    $total = $money($unit)->multipliedBy($quantity);

    [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
        deliveryLocation: deliveryTestLocation(), reference: 'SF-RC-'.Str::upper(Str::random(6)), idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address, billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => $quantity]], claimedUnitPrices: [$money($unit)],
        claimedTotals: ['subtotal' => $total, 'discount' => $money('0.00'), 'shipping' => $money('0.00'), 'tax' => $money('0.00'), 'grand_total' => $total],
        paymentMethod: $method,
    ));

    return $order->refresh();
}

/** An order delivered long ago, recorded in the delivery history only — no profit row yet. */
function reconcileDeliveredAgo(Order $order, int $hours = 72): void
{
    $at = CarbonImmutable::now()->subHours($hours);
    foreach ([OrderDeliveryStatus::CourierAssigned, OrderDeliveryStatus::Shipped, OrderDeliveryStatus::OutForDelivery] as $step) {
        $order->refresh()->moveDeliveryTo($step, new StatusChange(reason: 'history', at: $at->subHour()), OrderStatusChangeSource::Staff);
    }
    $order->refresh()->moveDeliveryTo(OrderDeliveryStatus::Delivered, new StatusChange(reason: 'history', at: $at), OrderStatusChangeSource::Staff);
}

/** COD confirmed by staff on the earlier per-line settlement, as old orders have it. */
function reconcileLegacyCod(Order $order): void
{
    foreach ($order->refresh()->items as $item) {
        app(EvaluateOrderProceedsEligibility::class)->markCodCollected($item, $item->line_total, CarbonImmutable::now()->subHours(60));
    }
}

/** Staff confirmed the cash `$hours` ago, through the audited action. */
function reconcileConfirmCod(Order $order, int $hours = 60): void
{
    test()->travelTo(CarbonImmutable::now()->subHours($hours));
    app(SettleStorefrontProfit::class)->confirmCodCollection(test()->staff, $order->refresh(), $order->total);
    test()->travelBack();
}

function reconcilePaid(Order $order, GatewayOwnership $ownership): void
{
    $payment = $order->payment;

    if ($ownership === GatewayOwnership::Partner) {
        $configuration = PartnerGatewayConfiguration::create(['business_account_id' => $order->business_account_id, 'website_id' => $order->website_id, 'provider' => 'sslcommerz', 'status' => 'active']);
        $version = PartnerGatewayConfigurationVersion::create([
            'partner_gateway_configuration_id' => $configuration->id, 'business_account_id' => $order->business_account_id, 'website_id' => $order->website_id,
            'version' => 1, 'provider' => 'sslcommerz', 'mode' => 'sandbox', 'credentials' => ['store_id' => 's', 'store_password' => 'p'],
        ]);
        DB::statement('ALTER TABLE payments DISABLE TRIGGER payments_gateway_ownership_set_once');
        DB::table('payments')->where('id', $payment->id)->update(['gateway_ownership' => 'partner', 'funds_destination' => 'partner', 'partner_gateway_configuration_version_id' => $version->id]);
        DB::statement('ALTER TABLE payments ENABLE TRIGGER payments_gateway_ownership_set_once');
    }

    $payment = $payment->refresh();
    $payment->transitionTo(PaymentStatus::Initiated)->save();
    $payment->transitionTo(PaymentStatus::Paid);
    $payment->forceFill(['completed_at' => now()->subDays(4), 'gateway_reference' => 'rc-'.Str::random(6)])->save();
}

function reconcileCredited(BusinessAccount $account): string
{
    return WalletTransaction::query()
        ->where('business_account_id', $account->id)
        ->where('idempotency_key', 'like', 'partner-storefront-profit:%')
        ->get()
        ->reduce(fn (Money $sum, WalletTransaction $t) => $sum->plus($t->amount), Money::zero(Currency::BDT))
        ->toDecimal();
}

function reconcileRow(array $result, Order $order): array
{
    return collect($result['rows'])->firstWhere('order', $order->reference);
}

describe('read-only by default, and idempotent', function () {
    it('writes nothing on a dry run', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        $before = [PartnerStorefrontProfit::count(), WalletTransaction::count(), DB::table('storefront_reconciliation_runs')->count(), AuditLog::count()];

        $result = reconcileRun(apply: false);

        expect(reconcileRow($result, $order)['classification'])->toBe('credited')
            ->and([PartnerStorefrontProfit::count(), WalletTransaction::count(), DB::table('storefront_reconciliation_runs')->count(), AuditLog::count()])->toBe($before)
            ->and(reconcileCredited($account))->toBe('0.00');
    });

    it('has no second effect when run again', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);

        $first = reconcileRun();
        $ledger = WalletTransaction::count();
        $second = reconcileRun();

        expect($first['counts']['profits_credited'])->toBe(1)
            ->and($second['counts']['profits_credited'])->toBe(0)
            ->and($second['counts']['settlements_created'])->toBe(0)
            ->and($second['counts']['withdrawable_credited'])->toBe('0.00')
            ->and(reconcileRow($second, $order)['classification'])->toBe('already_credited')
            ->and(WalletTransaction::count())->toBe($ledger)
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor(Wallet::query()->where('business_account_id', $account->id)->sole()))->toBe([]);
    });
});

describe('historical Partner profit', function () {
    it('credits an old delivered order with confirmed COD at once, past its 24 hours', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);

        $result = reconcileRun();

        expect(reconcileRow($result, $order)['classification'])->toBe('credited')
            ->and(reconcileCredited($account))->toBe('2200.00')
            ->and($result['counts']['withdrawable_credited'])->toBe('2200.00')
            ->and(WalletTransaction::query()->where('idempotency_key', 'partner-storefront-profit:'.$order->public_id)->count())->toBe(1);
    });

    it('credits nothing for a delivered order with no collection proof', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);

        $result = reconcileRun();

        expect(reconcileRow($result, $order)['classification'])->toBe('awaiting_cod_collection')
            ->and($result['counts']['awaiting_cod_collection'])->toBe(1)
            ->and(reconcileCredited($account))->toBe('0.00');
    });

    it('credits a verified Banij-gateway order', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00', 'online');
        reconcilePaid($order, GatewayOwnership::Platform);
        reconcileDeliveredAgo($order);

        reconcileRun();

        expect(reconcileCredited($account))->toBe('2200.00');
    });

    it('reports a Partner-gateway order and never credits it', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00', 'online');
        reconcilePaid($order, GatewayOwnership::Partner);
        reconcileDeliveredAgo($order);

        $result = reconcileRun();
        reconcileRun();

        expect(reconcileRow($result, $order)['classification'])->toBe('reported_only')
            ->and(reconcileCredited($account))->toBe('0.00');
    });

    it('credits a Conditional order from proven purchase lots', function () {
        [$account, $website] = reconcilePartner(AccountType::Conditional, '2200.00');
        reconcilePurchase($account, 3, '1500.00');
        $order = reconcilePlace($website, 2, '2200.00');
        reconcileDeliveredAgo($order);
        reconcileConfirmCod($order);

        reconcileRun();

        expect(reconcileCredited($account))->toBe('1400.00');
    });

    it('credits a sale bought at more than one price at the catalogue\'s wholesale price, never review', function () {
        [$account, $website] = reconcilePartner(AccountType::Conditional, '2200.00');
        reconcilePurchase($account, 1, '1000.00', '2026-10-01');
        reconcilePurchase($account, 1, '1200.00', '2026-10-02');
        $order = reconcilePlace($website, 1, '2200.00');
        concurrencyTestForgetCostLotsOf($order);
        reconcileDeliveredAgo($order);
        reconcileConfirmCod($order);

        $result = reconcileRun();

        // ৳2,200 less the product's ৳1,500 wholesale price.
        expect(reconcileRow($result, $order)['classification'])->toBe('credited')
            ->and($result['counts']['ambiguous_cost_basis'])->toBe(0)
            ->and(reconcileCredited($account))->toBe('700.00');
    });

    it('detects an earlier SalesCredit for the order and does not duplicate it', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        app(WalletService::class)->credit(app(OpenWallet::class)->handle($account, Currency::BDT), LedgerTransactionType::SalesCredit, Money::fromDecimal('200.00', Currency::BDT),
            new PostingContext(source: 'manual', description: 'Sales earnings for order '.$order->reference, idempotencyKey: 'legacy-manual-'.Str::random(6)), BalanceType::Withdrawable);

        $result = reconcileRun();

        expect(reconcileRow($result, $order)['classification'])->toBe('possible_duplicate_review_required')
            ->and(reconcileCredited($account))->toBe('0.00')
            ->and(PartnerStorefrontProfit::query()->where('order_id', $order->id)->value('review_hold'))->toBe('possible_duplicate');
    });

    it('counts only the units kept after a partial return', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        reconcileReturn($order, 1);

        reconcileRun();

        expect(reconcileCredited($account))->toBe('1100.00');
    });

    it('credits nothing when everything came back', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        reconcileReturn($order, 2);

        $result = reconcileRun();

        expect(reconcileCredited($account))->toBe('0.00')
            ->and(reconcileRow($result, $order)['classification'])->toBe('no_profit');
    });

    it('sends a negative profit to review', function () {
        [$account, $website] = reconcilePartner(AccountType::Conditional, '1400.00');
        reconcilePurchase($account, 1, '1600.00');
        $order = reconcilePlace($website, 1, '1400.00');
        reconcileDeliveredAgo($order);
        reconcileConfirmCod($order);

        $result = reconcileRun();

        expect(reconcileRow($result, $order)['classification'])->toBe('review_required')
            ->and(reconcileCredited($account))->toBe('0.00');
    });

    it('gives a Partner with no Wallet its first genuine credit', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        expect(Wallet::query()->where('business_account_id', $account->id)->exists())->toBeFalse();

        reconcileRun(apply: false);
        expect(Wallet::query()->where('business_account_id', $account->id)->exists())->toBeFalse();

        reconcileRun();
        expect(Wallet::query()->where('business_account_id', $account->id)->sole()->total->toDecimal())->toBe('2200.00');
    });

    it('corrects the account type only from a frozen placement record', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        DB::statement('ALTER TABLE orders DISABLE TRIGGER orders_account_type_locked');
        DB::table('orders')->where('id', $order->id)->update(['account_type' => 'conditional']);
        DB::statement('ALTER TABLE orders ENABLE TRIGGER orders_account_type_locked');

        $result = reconcileRun();

        expect($order->refresh()->account_type)->toBe(AccountType::NonConditional)
            ->and($result['counts']['account_types_corrected'])->toBe(1)
            ->and(fn () => DB::table('orders')->where('id', $order->id)->update(['account_type' => 'conditional']))->toThrow(QueryException::class);
    });
});

describe('runs', function () {
    it('resumes an interrupted run from its checkpoint without repeating finished orders', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $orders = collect([1, 2, 3])->map(function () use ($website) {
            $order = reconcilePlace($website, 1, '2600.00');
            reconcileDeliveredAgo($order);
            reconcileLegacyCod($order);

            return $order;
        });

        $first = reconcileRun(extra: ['limit' => 1]);
        DB::table('storefront_reconciliation_runs')->where('public_id', $first['run'])->update(['status' => 'failed']);

        $resumed = reconcileRun(extra: ['resume' => $first['run']]);

        expect(collect($resumed['rows'])->pluck('order')->all())->toBe($orders->slice(1)->pluck('reference')->values()->all())
            ->and($resumed['counts']['orders_scanned'])->toBe(3)
            ->and(reconcileCredited($account))->toBe('3300.00');
    });

    it('records one failing order and keeps the others', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $bad = reconcilePlace($website, 1, '2600.00');
        $good = reconcilePlace($website, 1, '2600.00');
        foreach ([$bad, $good] as $order) {
            reconcileDeliveredAgo($order);
            reconcileLegacyCod($order);
        }

        $settle = app(SettleStorefrontProfit::class);
        app()->instance(SettleStorefrontProfit::class, new class($settle, $bad->id) extends SettleStorefrontProfit
        {
            public function __construct(private SettleStorefrontProfit $real, private int $failOn)
            {
                parent::__construct(...array_values((fn () => get_object_vars($this))->call($real)));
            }

            public function sync(Order $order): ?PartnerStorefrontProfit
            {
                if ($order->id === $this->failOn) {
                    throw new RuntimeException('simulated failure');
                }

                return parent::sync($order);
            }
        });
        app()->forgetInstance(ReconcileStorefrontHistory::class);

        $result = reconcileRun();

        expect(reconcileRow($result, $bad)['classification'])->toBe('technical_failure')
            ->and(reconcileRow($result, $good)['classification'])->toBe('credited')
            ->and($result['counts']['technical_failures'])->toBe(1)
            ->and(reconcileCredited($account))->toBe('1100.00');
    });

    it('puts no customer data in the report or the audit trail', function () {
        [, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        $mobile = $order->customer['mobile'] ?? '+88017';
        $auditFrom = (int) AuditLog::max('id');

        $result = reconcileRun();
        $json = (string) file_get_contents($result['report']['json']);
        $csv = (string) file_get_contents($result['report']['csv']);
        $audit = AuditLog::query()->where('id', '>', $auditFrom)->get()->map(fn (AuditLog $log) => json_encode([$log->before, $log->after]))->implode(' ');

        foreach ([$json, $csv, $audit] as $text) {
            expect($text)->not->toContain('Ayesha')->not->toContain($mobile)->not->toContain('House 12');
        }
    });
});

describe('orders credited under the earlier formula', function () {
    /**
     * An order credited before 2026-11-28, when its central-stock cost was the
     * ৳2,500 suggested price: profit ৳5,200 − ৳5,000 = ৳200. The line's frozen
     * cost is set to what that formula charged, credited, then put back to the
     * frozen wholesale price — the state an old order is in today.
     */
    function reconcileCreditedUnderOldFormula(Order $order): void
    {
        $line = $order->items()->sole();
        $wholesale = $line->getRawOriginal('product_cost');

        DB::table('order_items')->where('id', $line->id)->update(['product_cost' => '5000.00']);
        reconcileRun();
        DB::table('order_items')->where('id', $line->id)->update(['product_cost' => $wholesale]);
    }

    function reconcileToppedUp(BusinessAccount $account): string
    {
        return WalletTransaction::query()
            ->where('business_account_id', $account->id)
            ->where('idempotency_key', 'like', 'partner-storefront-profit-top-up:%')
            ->get()
            ->reduce(fn (Money $sum, WalletTransaction $t) => $sum->plus($t->amount), Money::zero(Currency::BDT))
            ->toDecimal();
    }

    it('pays the difference once, under its own key, and nothing on a second run', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        reconcileCreditedUnderOldFormula($order);
        expect(reconcileCredited($account))->toBe('200.00');

        $first = reconcileRun();
        $second = reconcileRun();

        $profit = PartnerStorefrontProfit::query()->where('order_id', $order->id)->sole();
        expect(reconcileRow($first, $order)['classification'])->toBe('topped_up')
            ->and($first['counts']['profits_topped_up'])->toBe(1)
            ->and($first['counts']['withdrawable_credited'])->toBe('2000.00')
            ->and(reconcileToppedUp($account))->toBe('2000.00')
            ->and(WalletTransaction::query()->where('idempotency_key', 'partner-storefront-profit-top-up:'.$order->public_id.':1')->count())->toBe(1)
            ->and($profit->released_amount->toDecimal())->toBe('200.00')
            ->and($profit->topped_up_amount->toDecimal())->toBe('2000.00')
            ->and($profit->netCredited()->toDecimal())->toBe('2200.00')
            ->and(reconcileRow($second, $order)['classification'])->toBe('already_credited')
            ->and($second['counts']['profits_topped_up'])->toBe(0)
            ->and(reconcileToppedUp($account))->toBe('2000.00')
            ->and(app(VerifyLedgerIntegrity::class)->problemsFor(Wallet::query()->where('business_account_id', $account->id)->sole()))->toBe([]);
    });

    it('pays nothing extra while a return is open', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        reconcileCreditedUnderOldFormula($order);

        DB::table('order_returns')->insert([
            'public_id' => (string) Str::ulid(), 'reference' => 'RT-'.Str::upper(Str::random(8)), 'order_id' => $order->id,
            'business_account_id' => $order->business_account_id, 'website_id' => $order->website_id, 'source' => 'staff',
            'status' => 'requested', 'reason' => 'Customer changed their mind', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        reconcileRun();

        expect(reconcileToppedUp($account))->toBe('0.00')
            ->and(PartnerStorefrontProfit::query()->where('order_id', $order->id)->value('topped_up_amount'))->toBe('0.00');
    });

    it('takes back a topped-up profit in full when every unit comes back, never below zero', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);
        reconcileCreditedUnderOldFormula($order);
        reconcileRun();

        reconcileReturn($order, 2);
        app(SettleStorefrontProfit::class)->sync($order->refresh());

        $profit = PartnerStorefrontProfit::query()->where('order_id', $order->id)->sole();
        expect($profit->reversed_amount->toDecimal())->toBe('2200.00')
            ->and($profit->netCredited()->toDecimal())->toBe('0.00')
            ->and($profit->status->value)->toBe('reversed')
            ->and(Wallet::query()->where('business_account_id', $account->id)->sole()->total->isNegative())->toBeFalse();
    });
});

describe('the command', function () {
    it('reconciles only the chosen Storefront and placement dates, under either name', function () {
        [$chosen, $website] = reconcilePartner(AccountType::NonConditional);
        [$other, $otherWebsite] = reconcilePartner(AccountType::NonConditional);
        foreach ([reconcilePlace($website, 2, '2600.00'), reconcilePlace($otherWebsite, 2, '2600.00')] as $order) {
            reconcileDeliveredAgo($order);
            reconcileLegacyCod($order);
        }

        $this->artisan('storefront-profit:reconcile', ['--apply' => true, '--no-courier-api' => true, '--website' => $website->public_id, '--to' => now()->subYear()->toDateString()])
            ->expectsOutputToContain('Orders scanned')
            ->assertSuccessful();
        expect(reconcileCredited($chosen))->toBe('0.00');

        $this->artisan('storefront:reconcile-history', ['--apply' => true, '--no-courier-api' => true, '--website' => $website->public_id, '--from' => now()->subMonth()->toDateString()])
            ->assertSuccessful();

        expect(reconcileCredited($chosen))->toBe('2200.00')
            ->and(reconcileCredited($other))->toBe('0.00');
    });

    it('writes nothing without --apply', function () {
        [$account, $website] = reconcilePartner(AccountType::NonConditional);
        $order = reconcilePlace($website, 2, '2600.00');
        reconcileDeliveredAgo($order);
        reconcileLegacyCod($order);

        $this->artisan('storefront-profit:reconcile', ['--no-courier-api' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        expect(reconcileCredited($account))->toBe('0.00')
            ->and(PartnerStorefrontProfit::query()->where('order_id', $order->id)->exists())->toBeFalse();
    });
});

describe('courier consignments', function () {
    beforeEach(function () {
        shipmentLabelEnableSteadfast();
        $this->warehouse = shipmentLabelWarehouse();
        $this->order = shipmentLabelOrder();
        shipmentLabelLine($this->order, $this->warehouse);
    });

    it('reconciles an old Steadfast parcel without booking again, keeping its identifiers, and labels it', function () {
        $shipment = reconcileOldSteadfastShipment($this->order);
        Http::fake([SHIPMENT_LABEL_STEADFAST.'/status_by_invoice/*' => Http::response(['status' => 200, 'delivery_status' => 'in_review'])]);

        $result = reconcileRun(extra: ['courierApi' => true, 'order' => $this->order->reference]);
        reconcileRun(extra: ['courierApi' => true, 'order' => $this->order->reference]);

        $shipment->refresh();
        expect(shipmentLabelBookingCalls())->toBe(0)
            ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), 'status_by_invoice'))->count())->toBeGreaterThan(0)
            ->and($shipment->external_id)->toBe('1424107')
            ->and($shipment->tracking_number)->toBe('15BAEB8A')
            ->and(ShipmentLabel::query()->where('shipment_id', $shipment->id)->count())->toBe(1)
            ->and(ShipmentLabel::query()->where('shipment_id', $shipment->id)->value('issue_reason'))->toContain('Reconstructed')
            ->and($result['counts']['labels_generated'])->toBe(1);
    });

    it('sends a parcel with no consignment id or tracking code to review, with no label', function () {
        $shipment = reconcileOldSteadfastShipment($this->order, null, null);

        $result = reconcileRun(extra: ['order' => $this->order->reference]);

        expect(reconcileRow($result, $this->order)['reasons'])->toContain('courier_reference_required')
            ->and($result['counts']['missing_courier_references'])->toBe(1)
            ->and(ShipmentLabel::query()->where('shipment_id', $shipment->id)->count())->toBe(0);
    });

    it('labels only with a proven warehouse', function () {
        $this->warehouse->forceFill(['usable_for_courier_labels' => false])->save();
        $shipment = reconcileOldSteadfastShipment($this->order);

        $result = reconcileRun(extra: ['order' => $this->order->reference]);

        expect(reconcileRow($result, $this->order)['reasons'])->toContain('missing_warehouse')
            ->and(ShipmentLabel::query()->where('shipment_id', $shipment->id)->count())->toBe(0);
    });
});

/** A Steadfast parcel as old bookings left it: booked, never labelled. */
function reconcileOldSteadfastShipment(Order $order, ?string $consignment = '1424107', ?string $tracking = '15BAEB8A'): Shipment
{
    Bus::fake([IssueShipmentLabelJob::class]);

    $shipment = app(CreateShipmentFromAllocations::class)->handle(
        $order, CourierProviderCode::Steadfast, Money::fromDecimal('80.00', Currency::BDT), [[]],
        testPlatformStaff(PlatformRole::CourierManager), $tracking, Money::fromDecimal('1250.00', Currency::BDT),
    );
    $shipment->forceFill(['external_id' => $consignment, 'provider_status' => 'in_review'])->save();

    return $shipment->refresh();
}

/** A received return of `$quantity` units of the order's line. */
function reconcileReturn(Order $order, int $quantity): void
{
    $item = $order->items->first();
    $id = DB::table('order_returns')->insertGetId([
        'public_id' => (string) Str::ulid(), 'reference' => 'RT-'.Str::upper(Str::random(8)), 'order_id' => $order->id,
        'business_account_id' => $order->business_account_id, 'website_id' => $order->website_id, 'source' => 'staff',
        'status' => 'completed', 'reason' => 'Changed mind', 'requested_at' => now(), 'received_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_return_items')->insert([
        'public_id' => (string) Str::ulid(), 'order_return_id' => $id, 'order_item_id' => $item->id, 'quantity' => $quantity,
        'approved_quantity' => $quantity, 'received_quantity' => $quantity, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** As if the sale was placed before cost lots existed: its hold took no lot. */
function concurrencyTestForgetCostLotsOf(Order $order): void
{
    $reservation = $order->items->first()->stock_reservation_id;
    DB::statement('ALTER TABLE partner_stock_lot_movements DISABLE TRIGGER partner_stock_lot_movements_no_delete');
    DB::table('partner_stock_lot_movements')->where('stock_reservation_id', $reservation)->delete();
    DB::statement('ALTER TABLE partner_stock_lot_movements ENABLE TRIGGER partner_stock_lot_movements_no_delete');
}
