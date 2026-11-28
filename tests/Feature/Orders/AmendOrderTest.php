<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\RefundRequest;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\ReceivePartnerPurchase;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AmendOrder;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\SettleStorefrontProfit;
use App\Domain\Order\Data\OrderAmendment;
use App\Domain\Order\Data\OrderDetailsRevision;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\RevisionAdjustmentKind;
use App\Domain\Order\Enums\RevisionLineChange;
use App\Domain\Order\Enums\RevisionPaymentConsequence;
use App\Domain\Order\Exceptions\OrderRevisionRefused;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
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
use App\Support\StatusHistory\StatusChange;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * The Order Details editor's one save (D38, amended 2026-11-26): customer,
 * address, lines, prices, discount and delivery as one applied revision —
 * priced on the server, stock through the reservations, money followed, the
 * Partner's profit recalculated.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    websiteTestFee(FeeType::WebsiteDelivery, 0);
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);
    Http::preventStrayRequests();
    Http::fake(['*sslcommerz*' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1'])]);

    $this->product = websiteTestProduct();
    $this->other = websiteTestProduct(['name' => 'Silk scarf']);
    $warehouse = Warehouse::create(['code' => 'AMD', 'name' => 'Dhaka', 'is_default' => true]);
    StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->product->id, 'available' => 50]);
    StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->other->id, 'available' => 50]);
    $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);

    $this->account = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1', PackageFeature::ProductPublishLimit->value => null]);
    $this->account->forceFill(['account_type' => AccountType::NonConditional])->save();
    $this->website = Website::factory()->forAccount($this->account)->active()->create(['sms_verification_enabled' => false]);
    amendOrderList($this->website, $this->account, $this->product, '2600.00');
    amendOrderList($this->website, $this->account, $this->other, '3000.00');
});

function amendOrderList(Website $website, BusinessAccount $account, Product $product, string $price): void
{
    WebsiteProduct::create([
        'website_id' => $website->id, 'business_account_id' => $account->id, 'product_id' => $product->id,
        'status' => WebsiteProductStatus::Published, 'sync_status' => WebsiteSyncStatus::Pending, 'currency_code' => 'BDT',
        'price' => Money::fromDecimal($price, Currency::BDT), 'published_at' => now(),
    ]);
}

function amendOrderPlace(int $quantity = 2, string $method = 'cod'): Order
{
    $money = fn (string $amount) => Money::fromDecimal($amount, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];
    $total = $money('2600.00')->multipliedBy($quantity);

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        deliveryLocation: deliveryTestLocation(), reference: 'SF-AM-'.Str::upper(Str::random(6)), idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address, billingAddress: $address,
        items: [['sku' => test()->product->sku, 'quantity' => $quantity]], claimedUnitPrices: [$money('2600.00')],
        claimedTotals: ['subtotal' => $total, 'discount' => $money('0.00'), 'shipping' => $money('0.00'), 'tax' => $money('0.00'), 'grand_total' => $total],
        paymentMethod: $method,
    ));

    return $order->refresh();
}

/**
 * @param  list<array{id: string|null, sku: string|null, quantity: int, unit_price: string|null}>|null  $lines
 * @param  array<string, mixed>  $extra
 */
function amendOrderRequest(Order $order, ?array $lines = null, array $extra = []): OrderAmendment
{
    return new OrderAmendment(
        details: new OrderDetailsRevision(
            customer: $extra['customer'] ?? [],
            shippingAddress: $extra['shipping_address'] ?? [],
            billingAddress: [],
            customerNote: null,
            noteGiven: false,
            reason: $extra['reason'] ?? 'Customer called to change the order.',
            internalNote: null,
            baseRevision: $extra['base'] ?? $order->refresh()->revision_number,
            idempotencyKey: $extra['key'] ?? (string) Str::uuid(),
        ),
        lines: $lines,
        discount: $extra['discount'] ?? null,
        delivery: $extra['delivery'] ?? null,
        passwordConfirmed: $extra['password'] ?? false,
        twoFactorEnabled: $extra['password'] ?? false,
    );
}

function amendOrderLine(Order $order, int $quantity, ?string $unitPrice = null): array
{
    return ['id' => $order->items()->first()->public_id, 'sku' => null, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}

function amendOrderApply(Order $order, OrderAmendment $request, ?User $actor = null): OrderRevision
{
    return app(AmendOrder::class)->handle($actor ?? test()->staff, $order->refresh(), $request);
}

describe('lines and stock', function () {
    it('changes a quantity by superseding the line, moving its stock and the unpaid payment with it', function () {
        $order = amendOrderPlace(2);
        $old = $order->items()->first();
        $oldReservation = $old->stockReservation;

        $revision = amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 3)]));

        $order->refresh();
        $line = $order->items()->sole();

        expect($order->total->toDecimal())->toBe('7800.00')
            ->and($order->payment->amount->toDecimal())->toBe('7800.00')
            ->and($order->payment->allocationsBalance())->toBeTrue()
            ->and($line->quantity)->toBe(3)
            ->and($line->introduced_by_revision_id)->toBe($revision->id)
            ->and($line->platform_retained_amount?->toDecimal())->toBe('7500.00')
            ->and($old->refresh()->superseded_by_revision_id)->toBe($revision->id)
            // Central stock the confirmed order had committed: the two units go
            // back to sale, three are held for the new line.
            ->and($oldReservation->refresh()->status)->toBe(StockReservationStatus::Committed)
            ->and($line->stockReservation->quantity)->toBe(3)
            ->and(StockItem::query()->where('product_id', $this->product->id)->first()->only(['available', 'processing']))->toBe(['available' => 47, 'processing' => 3])
            ->and($revision->payment_consequence)->toBe(RevisionPaymentConsequence::CodAmountUpdated)
            ->and($revision->total_difference->toDecimal())->toBe('2600.00')
            ->and($revision->lines()->sole()->change)->toBe(RevisionLineChange::QuantityChanged)
            ->and($revision->adjustments()->pluck('kind')->all())->toContain(RevisionAdjustmentKind::StockReturnedFromProcessing, RevisionAdjustmentKind::StockReserved, RevisionAdjustmentKind::PaymentAmountUpdated)
            ->and($revision->stock_difference)->toBe([['sku' => $line->sku, 'before' => 2, 'after' => 3]]);
    });

    it('adds a product at the website\'s price and removes another', function () {
        $order = amendOrderPlace(2);

        $revision = amendOrderApply($order, amendOrderRequest($order, [
            ['id' => null, 'sku' => $this->other->sku, 'quantity' => 1, 'unit_price' => null],
        ]));

        $order->refresh();

        expect($order->items()->pluck('sku')->all())->toBe([$this->other->sku])
            ->and($order->total->toDecimal())->toBe('3000.00')
            ->and($order->allItems()->count())->toBe(2)
            ->and($revision->lines()->pluck('change')->map->value->sort()->values()->all())->toBe(['added', 'removed'])
            ->and(StockItem::query()->where('product_id', $this->product->id)->value('available'))->toBe(50)
            ->and(StockItem::query()->where('product_id', $this->other->id)->value('available'))->toBe(49);
    });

    it('refuses more units than there is stock for, and changes nothing', function () {
        $order = amendOrderPlace(2);
        $buckets = fn () => StockItem::query()->where('product_id', $this->product->id)->first()->only(['available', 'reserved', 'processing']);
        $before = $buckets();

        expect(fn () => amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 60)])))
            ->toThrow(OrderRevisionRefused::class, __('orders.revision.refused.insufficient_stock', ['sku' => $this->product->sku, 'quantity' => 60]));

        expect($order->refresh()->total->toDecimal())->toBe('5200.00')
            ->and(OrderRevision::query()->where('order_id', $order->id)->count())->toBe(0)
            ->and($buckets())->toBe($before);
    });
});

describe('the Partner\'s own stock and profit', function () {
    it('releases a Conditional Partner\'s hold and takes the new quantity from their lots, oldest first', function () {
        $this->account->forceFill(['account_type' => AccountType::Conditional])->save();
        foreach ([[1, '1000.00', '2026-10-01'], [4, '1200.00', '2026-10-05']] as [$quantity, $unit, $paidAt]) {
            $purchase = Order::factory()->create(['business_account_id' => $this->account->id, 'paid_at' => CarbonImmutable::parse($paidAt)]);
            $price = Money::fromDecimal($unit, Currency::BDT);
            $purchase->items()->create([
                'line_number' => 1, 'product_id' => $this->product->id, 'sku' => $this->product->sku, 'product_name' => 'x', 'quantity' => $quantity,
                'currency_code' => 'BDT', 'unit_price' => $price, 'line_subtotal' => $price->multipliedBy($quantity), 'line_total' => $price->multipliedBy($quantity),
            ]);
            app(ReceivePartnerPurchase::class)->handle($purchase->refresh());
        }
        $order = amendOrderPlace(2);
        $held = $order->items()->first()->stockReservation;

        $preview = app(AmendOrder::class)->preview($this->staff, $order, amendOrderRequest($order, [amendOrderLine($order, 3)]));
        amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 3)]));

        $line = $order->refresh()->items()->sole();
        expect($held->refresh()->status)->toBe(StockReservationStatus::Released)
            ->and($line->stockReservation->isPartnerSourced())->toBeTrue()
            ->and($line->stockReservation->status)->toBe(StockReservationStatus::Active)
            ->and($line->platform_retained_amount)->toBeNull()
            // 1 × ৳1,000 + 2 × ৳1,200: the oldest lot first.
            ->and($preview['profit']['product_base_price']['amount'])->toBe('3400.00')
            ->and($preview['profit']['partner_profit']['amount'])->toBe('4400.00');
    });

    it('settles the Partner\'s profit on the amended lines', function () {
        $order = amendOrderPlace(2);
        amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 3)]));

        $order->refresh();
        foreach ([OrderDeliveryStatus::CourierAssigned, OrderDeliveryStatus::Shipped, OrderDeliveryStatus::OutForDelivery, OrderDeliveryStatus::Delivered] as $step) {
            $order->refresh()->moveDeliveryTo($step, new StatusChange(reason: 'test'), OrderStatusChangeSource::Staff);
        }
        $profit = app(SettleStorefrontProfit::class)->confirmCodCollection($this->staff, $order->refresh(), $order->total);

        // Three units at the ৳1,500 wholesale price.
        expect($profit->selling_amount?->toDecimal())->toBe('7800.00')
            ->and($profit->cost_amount?->toDecimal())->toBe('4500.00')
            ->and($profit->profit_amount?->toDecimal())->toBe('3300.00');
    });
});

describe('prices and money', function () {
    it('asks for a confirmed password before changing a price, then reprices on the server', function () {
        $order = amendOrderPlace(2);

        try {
            amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 2, '2550.00')]));
            $this->fail('A price change went through without confirmation.');
        } catch (OrderRevisionRefused $refused) {
            expect($refused->refusal)->toBe('confirmation_required');
        }

        $revision = amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 2, '2550.00')], ['password' => true]));

        expect($order->refresh()->total->toDecimal())->toBe('5100.00')
            ->and($revision->sections)->toContain('pricing')
            ->and($revision->lines()->sole()->change)->toBe(RevisionLineChange::PriceChanged);
    });

    it('shares an order discount exactly and charges the delivery charge on top', function () {
        $order = amendOrderPlace(2);

        amendOrderApply($order, amendOrderRequest($order, null, ['discount' => '200.00', 'delivery' => '100.00', 'password' => true]));

        $order->refresh();
        $line = $order->items()->sole();

        expect($order->subtotal->toDecimal())->toBe('5200.00')
            ->and($order->discount->toDecimal())->toBe('200.00')
            ->and($order->delivery->toDecimal())->toBe('100.00')
            ->and($order->total->toDecimal())->toBe('5100.00')
            ->and($line->discount->toDecimal())->toBe('200.00')
            ->and($order->payment->amount->toDecimal())->toBe('5100.00');
    });

    it('requests a refund of exactly the difference on a paid order, and never lets a paid total grow', function () {
        $order = amendOrderPlace(2, 'online');
        $payment = $order->payment;
        $payment->transitionTo(PaymentStatus::Initiated)->save();
        $payment->transitionTo(PaymentStatus::Paid);
        $payment->forceFill(['completed_at' => now(), 'gateway_reference' => 'am-'.Str::random(6)])->save();

        expect(fn () => amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 3)])))
            ->toThrow(OrderRevisionRefused::class, __('orders.revision.refused.paid_total_cannot_increase'));

        $revision = amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 1)]));

        $refund = RefundRequest::query()->where('payment_id', $payment->id)->sole();
        expect($revision->payment_consequence)->toBe(RevisionPaymentConsequence::RefundDue)
            ->and($refund->amount->toDecimal())->toBe('2600.00')
            ->and($payment->refresh()->amount->toDecimal())->toBe('5200.00')
            ->and($order->refresh()->total->toDecimal())->toBe('2600.00');
    });
});

describe('the rules around it', function () {
    it('refuses an address change once a courier holds the order', function () {
        $order = amendOrderPlace(2);
        $order->moveDeliveryTo(OrderDeliveryStatus::CourierAssigned, new StatusChange(reason: 'test'), OrderStatusChangeSource::Staff);

        expect(fn () => amendOrderApply($order, amendOrderRequest($order, null, ['shipping_address' => ['line1' => 'House 99']])))
            ->toThrow(OrderRevisionRefused::class, __('orders.revision.refused.courier_booked'));
    });

    it('refuses a stale editor and applies the same request once', function () {
        $order = amendOrderPlace(2);
        $request = amendOrderRequest($order, [amendOrderLine($order, 3)], ['key' => 'same-key']);

        $first = amendOrderApply($order, $request);
        $again = amendOrderApply($order, $request);

        expect($again->id)->toBe($first->id)
            ->and(OrderRevision::query()->where('order_id', $order->id)->count())->toBe(1)
            ->and(fn () => amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 4)], ['base' => 0])))
            ->toThrow(OrderRevisionRefused::class, __('orders.revision.refused.revision_changed'));
    });

    it('needs the items permission to change lines', function () {
        $order = amendOrderPlace(2);
        $staff = User::factory()->staff()->withTwoFactor()->create();
        $staff->givePermissionTo('order.edit_customer');

        expect(fn () => amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 3)]), $staff))
            ->toThrow(AuthorizationException::class);
    });

    it('audits the change without the customer\'s details', function () {
        $order = amendOrderPlace(2);

        amendOrderApply($order, amendOrderRequest($order, [amendOrderLine($order, 3)], ['shipping_address' => ['line1' => 'House 77, Road 4']]));

        $log = AuditLog::query()->where('action', 'order.revised')->latest('id')->first();
        $text = json_encode([$log->before, $log->after]);

        expect($text)->not->toContain('House 77')->not->toContain('Ayesha')
            ->and($log->after['total'])->toBe('7800.00');
    });
});

describe('over HTTP', function () {
    it('previews as JSON and saves through the editor', function () {
        $order = amendOrderPlace(2);
        $line = $order->items()->first();
        $payload = [
            'lines' => [['id' => $line->public_id, 'quantity' => 3]],
            'base_revision' => 0,
            'idempotency_key' => 'http-amend-1',
        ];

        $this->actingAs($this->staff)
            ->postJson(route('admin.orders.amendment-preview', $order->public_id), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('totals.total.amount', '7800.00');

        $this->actingAs($this->staff)
            ->post(route('admin.orders.revisions.amend', $order->public_id), [...$payload, 'reason' => 'Customer asked for one more.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($order->refresh()->total->toDecimal())->toBe('7800.00');
    });

    it('leaves a deleted product out of what the editor offers, without failing the page', function () {
        config(['inertia.ssr.enabled' => false]);
        $order = amendOrderPlace(2);
        $this->other->delete();

        $this->actingAs($this->staff)
            ->get(route('admin.orders.show', $order->public_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('revision.amend.products', fn ($products) => collect($products)->pluck('sku')->all() === [$this->product->sku]));
    });

    it('takes the password in the editor before a price changes', function () {
        $order = amendOrderPlace(2);
        $payload = [
            'discount' => '100.00',
            'reason' => 'Goodwill discount for the delay.',
            'base_revision' => 0,
            'idempotency_key' => 'http-amend-2',
        ];

        $this->actingAs($this->staff)
            ->post(route('admin.orders.revisions.amend', $order->public_id), $payload)
            ->assertSessionHasErrors('password');

        $this->actingAs($this->staff)
            ->post(route('admin.orders.revisions.amend', $order->public_id), [...$payload, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->staff)
            ->post(route('admin.orders.revisions.amend', $order->public_id), [...$payload, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        expect($order->refresh()->total->toDecimal())->toBe('5100.00');
    });
});

describe('the preview', function () {
    it('shows the totals, money and Partner profit saving would give, and writes nothing', function () {
        $order = amendOrderPlace(2);
        $items = OrderItem::query()->count();

        $preview = app(AmendOrder::class)->preview($this->staff, $order, amendOrderRequest($order, [amendOrderLine($order, 3)], ['reason' => '']));

        expect($preview['ok'])->toBeTrue()
            ->and($preview['totals']['total']['amount'])->toBe('7800.00')
            ->and($preview['totals']['difference']['amount'])->toBe('2600.00')
            ->and($preview['payment_consequence'])->toBe('cod_amount_updated')
            ->and($preview['profit']['net_selling']['amount'])->toBe('7800.00')
            ->and($preview['profit']['product_base_price']['amount'])->toBe('4500.00')
            ->and($preview['profit']['delivery_charge']['amount'])->toBe('0.00')
            ->and($preview['profit']['partner_profit']['amount'])->toBe('3300.00')
            ->and($preview['profit']['banij_receivable']['amount'])->toBe('4500.00')
            ->and($order->refresh()->total->toDecimal())->toBe('5200.00')
            ->and(OrderItem::query()->count())->toBe($items)
            ->and(OrderRevision::query()->count())->toBe(0);
    });

    it('explains a refusal instead of throwing', function () {
        $order = amendOrderPlace(2);

        $preview = app(AmendOrder::class)->preview($this->staff, $order, amendOrderRequest($order, [amendOrderLine($order, 0)]));

        expect($preview['ok'])->toBeFalse()
            ->and($preview['refusal'])->toBe('quantity_invalid');
    });
});
