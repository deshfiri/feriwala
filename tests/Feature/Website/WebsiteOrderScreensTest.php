<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A partner reading the orders their own website took (§16.3, §18.4, P5-13,
 * P6-8, P6-10).
 *
 * Their shop's orders and nobody else's, in the words their customer was given —
 * never a staff note, never the reason Feriwala held an order, never the
 * gateway's own identifiers.
 */
beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    Http::fake(fn (ClientRequest $request) => Http::response(
        str_contains($request->url(), 'gwprocess')
            ? ['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']
            : ['status' => 'VALID', 'currency_amount' => '5260.00', 'currency_type' => 'BDT'],
    ));

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);

    $this->account = websiteTestAccount(limit: 3, extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Ayesha Fashion']);
    $this->selection = websiteOrderScreensSelection($this->website);
    websiteTestFee(FeeType::WebsiteDelivery, 6000);

    // An endpoint, so what the shop is told can be seen.
    app(ManageWebhookEndpoint::class)
        ->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');

    $this->order = websiteOrderScreensOrder($this->website, 'SF-2026-000481');
});

function websiteOrderScreensSelection(Website $website): WebsiteProduct
{
    $selection = WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 260000,
        'published_at' => now(),
    ]);

    $item = StockItem::create(['warehouse_id' => test()->warehouse->id, 'product_id' => $selection->product_id]);
    app(StockLedger::class)->move($item, null, StockBucket::Available, 20, StockMovementType::Adjustment);

    return $selection;
}

/**
 * One order on a website, placed the way the storefront places it.
 */
function websiteOrderScreensOrder(Website $website, string $reference): Order
{
    $selection = WebsiteProduct::query()->where('website_id', $website->id)->firstOrFail();
    $money = fn (int $minor) => Money::of($minor, Currency::BDT);

    $address = [
        'line1' => 'House 12, Road 5',
        'line2' => null,
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'postcode' => '1216',
        'country' => 'BD',
    ];

    [$order] = app(PlaceWebsiteOrder::class)->handle($website, new WebsiteOrderSubmission(
        reference: $reference,
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(
            name: 'Ayesha Rahman',
            mobile: '+88017'.random_int(10000000, 99999999),
            email: 'ayesha@example.test',
        ),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => $selection->product->sku, 'quantity' => 2]],
        claimedUnitPrices: [$money(260000)],
        claimedTotals: [
            'subtotal' => $money(520000),
            'discount' => $money(0),
            'shipping' => $money(6000),
            'tax' => $money(0),
            'grand_total' => $money(526000),
        ],
        paymentMethod: 'online',
        customerNote: 'Please call before delivery.',
    ));

    return $order;
}

describe('the list', function () {
    it('shows this website\'s orders to its owner, searched and filtered on the server', function () {
        websiteOrderScreensOrder($this->website, 'SF-2026-000482');

        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.index', $this->website->public_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('websites/orders')
                ->where('website.name', 'Ayesha Fashion')
                ->has('orders.data', 2)
                ->where('orders.data.0.customer', 'Ayesha Rahman')
                ->where('orders.data.0.payment_state', 'awaiting'));

        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.index', [$this->website->public_id, 'search' => 'SF-2026-000481']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.storefront_reference', 'SF-2026-000481'));

        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.index', [$this->website->public_id, 'status' => OrderStatus::Paid->value]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));
    });

    it('shows one order with what was bought, where it goes and what happened', function () {
        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('websites/order')
                ->where('order.reference', $this->order->reference)
                ->where('order.customer_details.mobile', $this->order->customer['mobile'])
                ->where('order.shipping_address.city', 'Dhaka')
                ->where('order.totals.total.minor_units', 526000)
                ->has('order.lines', 1)
                ->where('order.lines.0.quantity', 2)
                ->where('order.lines.0.reservation.status', 'active')
                ->has('order.timeline', 1)
                ->where('order.timeline.0.note', 'Order placed on the website. Waiting for the customer\'s payment.')
                ->where('can.cancel', true));
    });
});

describe('whose order it is', function () {
    it('does not find another partner\'s website, or an order on it', function () {
        $other = websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']);
        $theirs = Website::factory()->forAccount($other)->active()->create();
        websiteOrderScreensSelection($theirs);
        $theirOrder = websiteOrderScreensOrder($theirs, 'SF-THEIRS');

        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.index', $theirs->public_id))
            ->assertNotFound();

        // Their order, asked for through my own website: still not found.
        $this->actingAs($this->account->owner)
            ->get(route('websites.orders.show', [$this->website->public_id, $theirOrder->public_id]))
            ->assertNotFound();

        $this->actingAs($this->account->owner)
            ->post(route('websites.orders.cancellation.store', [$this->website->public_id, $theirOrder->public_id]))
            ->assertNotFound();

        expect($theirOrder->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });

    it('lets a member read the orders but not cancel one', function () {
        $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        $this->actingAs($staff)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can.cancel', false));

        $this->actingAs($staff)
            ->post(route('websites.orders.cancellation.store', [$this->website->public_id, $this->order->public_id]))
            ->assertForbidden();

        expect($this->order->refresh()->status)->toBe(OrderStatus::PaymentPending);
    });

    it('keeps the gateway, the staff notes and the hold reason off the page', function () {
        $this->order->forceFill(['held_at' => now(), 'hold_reason' => 'Stock could not be committed.'])->save();

        $response = $this->actingAs($this->account->owner)
            ->get(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]));

        // The order as the page receives it — the shared props carry the
        // signed-in person's own navigation, which is not this order's business.
        $order = json_encode($response->viewData('page')['props']['order']);

        expect($order)->not->toContain('Stock could not be committed.')
            ->and($order)->not->toContain('sslcommerz')
            ->and($order)->not->toContain($this->order->payment->reference)
            ->and($order)->not->toContain('warehouse')
            ->and($order)->not->toContain('hold_reason')
            ->and($order)->not->toContain('gateway_reference');
    });
});

describe('cancelling before payment', function () {
    it('cancels the order, gives the stock back and tells the shop', function () {
        $this->actingAs($this->account->owner)
            ->post(route('websites.orders.cancellation.store', [$this->website->public_id, $this->order->public_id]))
            ->assertRedirect(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]));

        $this->order->refresh();

        expect($this->order->status)->toBe(OrderStatus::Cancelled)
            ->and($this->order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Released)
            ->and($this->order->payment->refresh()->status)->toBe(PaymentStatus::Cancelled);

        expect(WebhookDelivery::query()->where('event_type', WebhookEvent::OrderCancelled->value)->count())->toBe(1);
    });

    it('refuses to cancel an order that has been paid', function () {
        $this->post(route('webhooks.payment', 'sslcommerz'), websiteOrderScreensIpn((string) $this->order->payment?->reference));

        expect($this->order->refresh()->status)->toBe(OrderStatus::Paid);

        $this->actingAs($this->account->owner)
            ->from(route('websites.orders.show', [$this->website->public_id, $this->order->public_id]))
            ->post(route('websites.orders.cancellation.store', [$this->website->public_id, $this->order->public_id]))
            ->assertSessionHasErrors('order');

        expect($this->order->refresh()->status)->toBe(OrderStatus::Paid);
    });
});

/**
 * @return array<string, string>
 */
function websiteOrderScreensIpn(string $reference): array
{
    $fields = ['tran_id' => $reference, 'val_id' => 'val-1', 'status' => 'VALID'];
    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed))),
    ];
}
