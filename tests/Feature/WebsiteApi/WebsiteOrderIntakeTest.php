<?php

use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Enums\PartnerStockMovementType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\PartnerStockItem;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Inventory\StockReservations;
use App\Domain\Location\Enums\BdLocationType;
use App\Domain\Location\Models\BdLocation;
use App\Domain\Order\Actions\ExpireUnpaidOrders;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Wallet\Models\LedgerEntry;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\DomainRoutingStatus;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\StorefrontRequest;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCustomer;
use App\Domain\Website\Models\WebsiteDomain;
use App\Domain\Website\Models\WebsiteProduct;
use App\Notifications\Orders\WebsiteOrderPaid;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Orders arriving from a partner website (contract §6.1, §6.1.1, §6.1.2, §6.2,
 * §17.3, P5-21, P5-23, P5-30).
 *
 * The ERP prices the order itself, holds the stock, opens the payment and
 * refuses anything it cannot verify. The same request never becomes two orders.
 */
beforeEach(function () {
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    $this->gateway = new ArrayObject(['reachable' => true]);
    $this->validation = new ArrayObject(['status' => 'VALID', 'currency_amount' => '5260.00', 'currency_type' => 'BDT']);

    Http::fake(function (ClientRequest $request) {
        if (str_contains($request->url(), 'gwprocess')) {
            if (! $this->gateway['reachable']) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://pay.test/go', 'sessionkey' => 'session-1']);
        }

        return Http::response($this->validation->getArrayCopy());
    });

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['domain' => 'shop.test']);

    [$this->credential, $this->secret] = storefrontCredential($this->website, [
        CredentialScope::OrdersWrite, CredentialScope::OrdersRead, CredentialScope::CustomersWrite,
    ]);

    // 26 taka a kettle, 60 taka delivery: two kettles come to 5,260 taka.
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');

    $this->selection = websiteOrderSelection($this->website, 260000);
    $this->stock = websiteOrderStock($this->selection, 20);
    websiteTestFee(FeeType::WebsiteDelivery, 6000);
});

/**
 * A published selection on a website, at its own selling price.
 */
function websiteOrderSelection(Website $website, int $priceMinor, array $productAttributes = []): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct([
            'category_id' => Category::query()->where('is_active', true)->value('id')
                ?? Category::create(['name' => 'Kitchen', 'slug' => 'kitchen-'.Str::lower(Str::random(6)), 'is_active' => true])->id,
            ...$productAttributes,
        ])->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        // `$priceMinor` keeps its old poisha-shorthand name so call sites do
        // not all need to change, but it is converted to exact Taka once,
        // here, via bcmath — never scaled at the column or the cast (D26).
        'price' => Money::fromDecimal(bcdiv((string) $priceMinor, '100', 2), Currency::BDT),
        'published_at' => now(),
    ]);
}

/**
 * The units the selection's owner bought — a paid wholesale purchase, through the
 * real credit — and the stock row they sit in (D34).
 */
function websiteOrderStock(WebsiteProduct $selection, int $units): PartnerStockItem
{
    $account = BusinessAccount::query()->findOrFail($selection->business_account_id);

    websiteTestOwns($account, $selection->product, $units);

    return app(PartnerStock::class)->itemFor($account, $selection->product_id, null);
}

/**
 * An order as a storefront submits it, priced the way the ERP would price it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function websiteOrderBody(array $overrides = []): array
{
    $money = fn (string $amount) => ['amount' => $amount, 'currency' => 'BDT'];

    return array_replace_recursive([
        'storefront_order_reference' => 'SF-2026-000481',
        'placed_at' => now()->toIso8601String(),
        'customer' => [
            'name' => 'Ayesha Rahman',
            'phone' => '01712-345678',
            'email' => 'ayesha@example.test',
            'storefront_customer_reference' => 'cus_8813',
            'is_guest' => true,
        ],
        'shipping_address' => [
            'line1' => 'House 12, Road 5',
            'city' => 'Dhaka',
            'district' => 'Dhaka',
            'postcode' => '1216',
            'country' => 'BD',
        ],
        'items' => [
            ['sku' => test()->selection->product->sku, 'quantity' => 2, 'unit_price' => $money('2600.00')],
        ],
        'totals' => [
            'subtotal' => $money('5200.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('60.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('5260.00'),
        ],
        'payment' => ['method' => 'online'],
    ], $overrides);
}

/**
 * Submit an order over the signed API.
 *
 * @param  array<string, mixed>  $overrides
 */
function websiteOrderSubmit(array $overrides = [], ?string $key = null, ?array $body = null, ?Website $website = null, ?array $credential = null): TestResponse
{
    [$record, $secret] = $credential ?? [test()->credential, test()->secret];
    $payload = json_encode($body ?? websiteOrderBody($overrides));

    return storefrontCall(
        $record,
        $secret,
        'orders',
        method: 'POST',
        body: (string) $payload,
        overrides: ['headers' => ['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Content-Type' => 'application/json']],
    );
}

function websiteOrderIpn(string $reference, string $valId = 'val-1'): array
{
    $fields = ['tran_id' => $reference, 'val_id' => $valId, 'status' => 'VALID'];
    $signed = [...$fields, 'store_passwd' => md5('pass')];
    ksort($signed);

    return $fields + [
        'verify_key' => 'tran_id,val_id,status',
        'verify_sign' => md5(implode('&', array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($signed), $signed))),
    ];
}

function websiteOrderPay(Order $order): void
{
    test()->post(route('webhooks.payment', 'sslcommerz'), websiteOrderIpn((string) $order->payment?->reference));
}

describe('taking the order', function () {
    it('takes a submitted order, prices it, holds its stock and opens its payment', function () {
        $response = websiteOrderSubmit();

        $response->assertCreated()
            ->assertJsonPath('status', OrderStatus::PaymentPending->value)
            ->assertJsonPath('storefront_order_reference', 'SF-2026-000481')
            // The response carries both the flat-Taka `amount` and the frozen
            // contract's legacy `minor_units` compatibility key (§4.1).
            ->assertJsonPath('totals.grand_total.amount', '5260.00')
            ->assertJsonPath('totals.grand_total.minor_units', 526000)
            ->assertJsonPath('payment.redirect_url', 'https://pay.test/go')
            ->assertJsonPath('items.0.quantity', 2);

        expect($response->json('stock_reservation.expires_at'))->not->toBeNull();

        $order = websiteTestOrders()->sole();
        $line = $order->items()->sole();
        $payment = $order->payment;

        expect($order->source)->toBe(OrderSource::Website)
            ->and($order->website_id)->toBe($this->website->id)
            ->and($order->business_account_id)->toBe($this->account->id)
            ->and($order->placed_by)->toBeNull()
            ->and($order->cart_id)->toBeNull()
            ->and($order->subtotal->toDecimal())->toBe('5200.00')
            ->and($order->delivery->toDecimal())->toBe('60.00')
            ->and($order->total->toDecimal())->toBe('5260.00')
            ->and($order->customer['mobile'])->toBe('+8801712345678')
            ->and($order->shipping_address['city'])->toBe('Dhaka')
            // Nothing was sent, so the billing address is the shipping one.
            ->and($order->billing_address['line1'])->toBe('House 12, Road 5');

        expect($line->sku)->toBe($this->selection->product->sku)
            ->and($line->website_product_id)->toBe($this->selection->id)
            ->and($line->unit_price->toDecimal())->toBe('2600.00')
            ->and($line->line_total->toDecimal())->toBe('5200.00')
            ->and($line->stockReservation->status)->toBe(StockReservationStatus::Active)
            ->and($line->stockReservation->quantity)->toBe(2);

        expect($payment)->not->toBeNull()
            ->and($payment->purpose)->toBe(PaymentPurpose::WebsiteOrder)
            ->and($payment->business_account_id)->toBe($this->account->id)
            ->and($payment->amount->toDecimal())->toBe('5260.00')
            ->and($payment->gateway)->toBe('sslcommerz')
            ->and($payment->expires_at)->not->toBeNull()
            // An order nobody has paid for carries no invoice yet (P4-11).
            ->and($payment->invoice()->exists())->toBeFalse();

        expect($payment->allocations->pluck('type')->map(fn ($type) => $type->value)->all())
            ->toBe(['website_goods', 'delivery_charge']);

        expect($order->statusHistory()->sole()->source->value)->toBe('storefront');

        // The customer is this website's, keyed on the normalised number.
        $customer = WebsiteCustomer::query()->sole();

        expect($customer->website_id)->toBe($this->website->id)
            ->and($customer->mobile)->toBe('+8801712345678')
            ->and($customer->storefront_customer_reference)->toBe('cus_8813')
            ->and($order->website_customer_id)->toBe($customer->id);
    });

    it('takes the order even when the gateway cannot be reached, and opens the payment later', function () {
        $this->gateway['reachable'] = false;

        $response = websiteOrderSubmit();
        $response->assertCreated()->assertJsonPath('payment.redirect_url', null);

        $order = websiteTestOrders()->sole();

        expect($order->status)->toBe(OrderStatus::PaymentPending);

        $this->gateway['reachable'] = true;

        storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id.'/payment-session', method: 'POST', overrides: [
            'headers' => ['Idempotency-Key' => (string) Str::uuid()],
        ])->assertOk()->assertJsonPath('redirect_url', 'https://pay.test/go');
    });

    it('refuses a total that differs by a single poisha, and says what the figures really are', function () {
        /*
         * Deliberately submitted through the frozen contract's legacy
         * `minor_units` request shape (§4.1) — one Taka is still 100 minor
         * units on the way in. The whole `grand_total` object is replaced
         * (rather than merged over the default `amount` one) because the two
         * money shapes are mutually exclusive per field. The refusal's own
         * money objects, though, never pass through the response's
         * legacy-key adapter, so they carry only the flat-Taka `amount`.
         */
        $body = websiteOrderBody();
        $body['totals']['grand_total'] = ['minor_units' => 525999, 'currency' => 'BDT'];

        $response = websiteOrderSubmit(body: $body);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'price_mismatch')
            ->assertJsonPath('error.details.authoritative.grand_total.amount', '5260.00')
            ->assertJsonPath('error.details.submitted_grand_total.amount', '5259.99');

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('refuses a money object naming both the flat-Taka amount and the legacy minor_units key', function () {
        $body = websiteOrderBody();
        $body['totals']['grand_total'] = ['amount' => '5260.00', 'minor_units' => 525999, 'currency' => 'BDT'];

        $response = websiteOrderSubmit(body: $body);

        $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        expect($response->json('error.details.fields'))->toHaveKeys([
            'totals.grand_total.amount',
            'totals.grand_total.minor_units',
        ]);

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('refuses a price the storefront made up, and never sells at it', function () {
        $response = websiteOrderSubmit([
            'items' => [['sku' => $this->selection->product->sku, 'quantity' => 2, 'unit_price' => ['amount' => '1.00', 'currency' => 'BDT']]],
            'totals' => ['subtotal' => ['amount' => '2.00'], 'grand_total' => ['amount' => '62.00']],
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'price_mismatch');
        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('refuses a product this website does not sell, and one it has unpublished', function () {
        websiteOrderSubmit(['items' => [['sku' => 'NOT-A-SKU', 'quantity' => 1, 'unit_price' => ['amount' => '2600.00', 'currency' => 'BDT']]]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'product_unavailable');

        $this->selection->forceFill(['status' => WebsiteProductStatus::Unpublished])->save();

        websiteOrderSubmit()->assertStatus(422)->assertJsonPath('error.code', 'product_unavailable');

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('refuses more units than the stock holds, and says how many are left', function () {
        websiteOrderSubmit([
            'items' => [['sku' => $this->selection->product->sku, 'quantity' => 50, 'unit_price' => ['amount' => '2600.00', 'currency' => 'BDT']]],
            'totals' => ['subtotal' => ['amount' => '130000.00'], 'grand_total' => ['amount' => '130060.00']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_stock')
            ->assertJsonPath('error.details.available', 20);

        expect(websiteTestOrders()->count())->toBe(0)
            ->and($this->stock->refresh()->reserved)->toBe(0);
    });

    it('never believes a payment the storefront says is done, and has no cash on delivery yet', function () {
        websiteOrderSubmit(['payment' => ['method' => 'online', 'status' => 'paid', 'gateway_reference' => 'SSLCZ_TXN_1']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_unverified');

        websiteOrderSubmit(['payment' => ['method' => 'cod']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        websiteOrderSubmit(['coupon_code' => 'EID10'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'coupon_not_applicable');

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('refuses a mobile number it cannot resolve, and a return address that is not the shop\'s', function () {
        websiteOrderSubmit(['customer' => ['phone' => '12345']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mobile_number');

        websiteOrderSubmit(['payment' => ['method' => 'online', 'return_url' => 'https://evil.test/thanks']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'return_url_not_allowed');

        // Recorded on the website but never verified: not the shop's (H8).
        websiteOrderSubmit(['payment' => ['method' => 'online', 'return_url' => 'https://shop.test/thanks']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'return_url_not_allowed');

        WebsiteDomain::factory()->serving()->create(['website_id' => $this->website->id, 'domain' => 'shop.test']);

        websiteOrderSubmit(['payment' => ['method' => 'online', 'return_url' => 'https://shop.test/thanks']])
            ->assertCreated();
    });

    it('checks the return address again when the customer comes back, and sends them home when it no longer holds', function () {
        $domain = WebsiteDomain::factory()->serving()->create(['website_id' => $this->website->id, 'domain' => 'shop.test']);

        websiteOrderSubmit(['payment' => ['method' => 'online', 'return_url' => 'https://shop.test/thanks']])->assertCreated();

        $order = websiteTestOrders()->sole();
        $home = 'https://'.$this->website->subdomain.'.'.config('website.storefront_domain').'/';

        $this->post(route('website-orders.payment.return', $order->public_id))
            ->assertStatus(303)
            ->assertRedirect('https://shop.test/thanks?order='.$order->public_id.'&payment=return');

        // The domain lapses after the order was taken.
        $domain->forceFill(['routing_status' => DomainRoutingStatus::Suspended])->save();

        $this->post(route('website-orders.payment.return', $order->public_id))
            ->assertStatus(303)
            ->assertRedirect($home.'?order='.$order->public_id.'&payment=return');

        // The name now serves another website: the customer is never sent there.
        $domain->delete();
        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        WebsiteDomain::factory()->serving()->create(['website_id' => $other->id, 'domain' => 'shop.test']);

        $this->post(route('website-orders.payment.cancelled', $order->public_id))
            ->assertStatus(303)
            ->assertRedirect($home.'?order='.$order->public_id.'&payment=cancel');
    });

    it('refuses an order to a website that is not live', function () {
        $this->website->forceFill(['status' => WebsiteStatus::Maintenance])->save();

        websiteOrderSubmit()->assertStatus(409)->assertJsonPath('error.code', 'website_not_accepting_orders');
    });
});

describe('never twice (contract §4.7, §17.3, P5-21)', function () {
    it('answers a retried key with the answer it gave, and places no second order', function () {
        $key = (string) Str::uuid();
        $body = websiteOrderBody();

        $first = websiteOrderSubmit(key: $key, body: $body)->assertCreated();
        $second = websiteOrderSubmit(key: $key, body: $body)->assertCreated();

        expect($second->getContent())->toBe($first->getContent())
            ->and($second->headers->get('Idempotent-Replay'))->toBe('true')
            ->and(websiteTestOrders()->count())->toBe(1)
            ->and(Payment::query()->where('purpose', PaymentPurpose::WebsiteOrder)->count())->toBe(1)
            ->and(StorefrontRequest::query()->count())->toBe(1)
            // Nor a second of anything the first one wrote.
            ->and(Invoice::query()->count())->toBe(0)
            ->and(StockReservation::query()->count())->toBe(1)
            ->and(DB::table('order_status_history')->count())->toBe(1)
            ->and(WebhookDelivery::query()->count())->toBe(0)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });

    it('refuses the same key carrying a different order', function () {
        $key = (string) Str::uuid();

        websiteOrderSubmit(key: $key)->assertCreated();

        websiteOrderSubmit(['storefront_order_reference' => 'SF-OTHER'], key: $key)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_key_reused');

        expect(websiteTestOrders()->count())->toBe(1);
    });

    it('refuses a second order for one storefront reference, even with a fresh key', function () {
        websiteOrderSubmit()->assertCreated();

        websiteOrderSubmit()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'duplicate_storefront_order');

        expect(websiteTestOrders()->count())->toBe(1);
    });

    it('lets two websites use the same storefront reference', function () {
        websiteOrderSubmit()->assertCreated();

        $other = Website::factory()->forAccount($this->account)->active()->create();
        $selection = websiteOrderSelection($other, 260000);
        websiteOrderStock($selection, 10);
        $credential = storefrontCredential($other, [CredentialScope::OrdersWrite, CredentialScope::OrdersRead]);

        $body = websiteOrderBody(['items' => [['sku' => $selection->product->sku, 'quantity' => 2, 'unit_price' => ['amount' => '2600.00', 'currency' => 'BDT']]]]);

        websiteOrderSubmit(body: $body, credential: $credential)->assertCreated();

        expect(websiteTestOrders()->count())->toBe(2)
            ->and(websiteTestOrders()->where('storefront_order_reference', 'SF-2026-000481')->count())->toBe(2);
    });

    it('needs an idempotency key at all', function () {
        $body = (string) json_encode(websiteOrderBody());

        storefrontCall($this->credential, $this->secret, 'orders', method: 'POST', body: $body)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'idempotency_key_required');
    });
});

describe('reading back and cancelling (contract §5.3, §6.1.2)', function () {
    it('shows the order to its own website and nobody else', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('reference', $order->reference)
            ->assertJsonPath('timeline.0.note', 'Order placed on the website. Waiting for the customer\'s payment.');

        storefrontCall($this->credential, $this->secret, 'orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $other = Website::factory()->forAccount($this->account)->active()->create();
        [$credential, $secret] = storefrontCredential($other, [CredentialScope::OrdersRead, CredentialScope::OrdersWrite]);

        storefrontCall($credential, $secret, 'orders/'.$order->public_id)->assertNotFound();
        storefrontCall($credential, $secret, 'orders')->assertOk()->assertJsonCount(0, 'data');
    });

    it('never shows a staff note or the reason an order was held', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        app(StockReservations::class)->release($order->items()->sole()->stockReservation, 'Taken for a test.');
        websiteOrderPay($order);

        expect($order->refresh()->status)->toBe(OrderStatus::OnHold);

        $body = storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id)->assertOk()->getContent();

        expect($body)->not->toContain('could not be committed')
            ->and($body)->toContain('on_hold');
    });

    it('cancels an unpaid order at the customer\'s request and gives the stock back', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id.'/cancellation', method: 'POST', overrides: [
            'headers' => ['Idempotency-Key' => (string) Str::uuid()],
        ])->assertOk()->assertJsonPath('status', OrderStatus::Cancelled->value);

        expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Released)
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($this->stock->refresh()->reserved)->toBe(0);

        expect(WebhookDelivery::query()->where('event_type', WebhookEvent::OrderCancelled->value)->count())->toBe(1);
    });

    it('refuses to cancel an order that has been paid', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        websiteOrderPay($order);

        expect($order->refresh()->status)->toBe(OrderStatus::Paid);

        storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id.'/cancellation', method: 'POST', overrides: [
            'headers' => ['Idempotency-Key' => (string) Str::uuid()],
        ])->assertStatus(409)->assertJsonPath('error.code', 'order_not_cancellable');
    });
});

describe('what a settled payment does (P5-23, P6-15)', function () {
    it('pays the order, keeps the stock held for dispatch, issues the invoice and tells the shop', function () {
        Notification::fake();

        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        websiteOrderPay($order);

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Paid)
            ->and($order->paid_at)->not->toBeNull()
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Active)
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Paid)
            ->and($order->payment->invoice()->exists())->toBeTrue();

        expect($this->stock->refresh()->reserved)->toBe(2);

        Notification::assertSentTo($this->account->owner, WebsiteOrderPaid::class);

        // The storefront is told, through the delivery system every event uses,
        // and told nothing about a confirmation an online order never needed.
        expect(WebhookDelivery::query()->where('event_type', WebhookEvent::OrderStatusChanged->value)->count())->toBe(1)
            ->and(WebhookDelivery::query()->whereIn('event_type', [
                WebhookEvent::CodConfirmationRequired->value,
                WebhookEvent::CodConfirmed->value,
                WebhookEvent::CodExpired->value,
            ])->count())->toBe(0);
    });

    it('holds a paid order whose stock is gone, for a person to resolve', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        // Somebody released the stock while the payment was still open — the
        // override the contract allows (§6.1.2). The money then arrives.
        app(StockReservations::class)->release($order->items()->sole()->stockReservation, 'Needed for another order.');

        websiteOrderPay($order);

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::OnHold)
            ->and($order->hold_reason)->not->toBeNull()
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Paid)
            // Money arrived, so no invoice is issued for an order nobody can fulfil yet.
            ->and($order->payment->invoice()->exists())->toBeFalse();
    });

    it('sends a payment that lands after the window to reconciliation, not to the order', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        $order->payment->forceFill(['expires_at' => now()->subMinute()])->save();

        websiteOrderPay($order);

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::ReconciliationRequired)
            ->and($order->payment->reconciliation_reason)->not->toBeNull();
    });
});

describe('when nobody pays (§19.1, P5-23)', function () {
    it('gives the stock back when the window closes, and tells the shop', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        // The window shuts a margin before the stock is released, so a
        // settlement that started in time still finds its stock.
        $this->travelTo($order->payment->expires_at->addMinute());

        $counts = app(ExpireUnpaidOrders::class)->handle();

        $order->refresh();

        expect($counts['expired'])->toBe(1)
            ->and($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancellation_reason)->toBe('The time to pay ran out.')
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Released)
            ->and($order->payment->refresh()->status)->toBe(PaymentStatus::Cancelled)
            ->and($this->stock->refresh()->reserved)->toBe(0);

        expect(WebhookDelivery::query()->where('event_type', WebhookEvent::OrderCancelled->value)->count())->toBe(1);
    });

    it('lets the storefront read the cancelled order back, with the reason in its own words', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();

        $this->travelTo($order->payment->expires_at->addMinute());
        app(ExpireUnpaidOrders::class)->handle();

        $body = storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id)->assertOk();

        $body->assertJsonPath('status', OrderStatus::Cancelled->value);

        expect(collect($body->json('timeline'))->pluck('note')->last())
            ->toBe('Cancelled because the time to pay ran out. The stock held for it was released.');
    });
});

describe('the website\'s customers (contract §6.2)', function () {
    it('keys a customer on the website and the normalised number, and keeps one per number', function () {
        $call = fn (array $body) => storefrontCall($this->credential, $this->secret, 'customers', method: 'POST', body: (string) json_encode($body), overrides: [
            'headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json'],
        ]);

        $first = $call(['name' => 'Ayesha Rahman', 'phone' => '+8801712345678'])->assertCreated();
        $second = $call(['name' => 'Someone Else', 'phone' => '01712 345678'])->assertCreated();

        expect($second->json('id'))->toBe($first->json('id'))
            ->and(WebsiteCustomer::query()->count())->toBe(1)
            // Knowing the number does not rewrite who they are.
            ->and(WebsiteCustomer::query()->sole()->name)->toBe('Ayesha Rahman');

        $call(['name' => 'Nobody', 'phone' => 'not-a-number'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mobile_number');
    });

    it('is another customer on another website, and answers nothing about one', function () {
        storefrontCall($this->credential, $this->secret, 'customers', method: 'POST', body: (string) json_encode([
            'name' => 'Ayesha Rahman', 'phone' => '+8801712345678',
        ]), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])->assertCreated();

        $other = Website::factory()->forAccount($this->account)->active()->create();
        [$credential, $secret] = storefrontCredential($other, [CredentialScope::CustomersWrite]);

        storefrontCall($credential, $secret, 'customers', method: 'POST', body: (string) json_encode([
            'name' => 'Ayesha Rahman', 'phone' => '+8801712345678',
        ]), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])->assertCreated();

        expect(WebsiteCustomer::query()->count())->toBe(2);

        // There is no endpoint that reads a customer back at all: a mobile
        // number is never a way into somebody's history (contract §6.2).
        storefrontCall($credential, $secret, 'customers/'.WebsiteCustomer::query()->first()?->public_id)
            ->assertMethodNotAllowed();
    });

    it('changes a customer only through the identifier the storefront was given', function () {
        websiteOrderSubmit()->assertCreated();
        $customer = WebsiteCustomer::query()->sole();

        storefrontCall($this->credential, $this->secret, 'customers/'.$customer->public_id, method: 'PATCH', body: (string) json_encode([
            'name' => 'Ayesha R.', 'email' => null,
        ]), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])
            ->assertOk()
            ->assertJsonPath('id', $customer->public_id);

        expect($customer->refresh()->name)->toBe('Ayesha R.')
            ->and($customer->email)->toBeNull();

        $other = Website::factory()->forAccount($this->account)->active()->create();
        [$credential, $secret] = storefrontCredential($other, [CredentialScope::CustomersWrite]);

        storefrontCall($credential, $secret, 'customers/'.$customer->public_id, method: 'PATCH', body: (string) json_encode(['name' => 'Taken']), overrides: [
            'headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json'],
        ])->assertNotFound();

        expect($customer->refresh()->name)->toBe('Ayesha R.');
    });

    it('does not keep an order snapshot in step with a later customer edit', function () {
        websiteOrderSubmit()->assertCreated();
        $order = websiteTestOrders()->sole();
        $customer = WebsiteCustomer::query()->sole();

        storefrontCall($this->credential, $this->secret, 'customers/'.$customer->public_id, method: 'PATCH', body: (string) json_encode([
            'name' => 'Changed Later',
        ]), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])->assertOk();

        expect($order->refresh()->customer['name'])->toBe('Ayesha Rahman');
    });
});

/**
 * Ask for a checkout quote over the signed API.
 *
 * @param  array<string, mixed>  $body
 * @param  array{0: mixed, 1: string}|null  $credential
 */
function websiteOrderQuote(array $body = [], ?array $credential = null): TestResponse
{
    [$record, $secret] = $credential ?? [test()->credential, test()->secret];

    return storefrontCall($record, $secret, 'checkout/quote', method: 'POST', body: (string) json_encode([
        'items' => [['sku' => test()->selection->product->sku, 'quantity' => 2]],
        'payment_method' => 'online',
        ...$body,
    ]), overrides: ['headers' => ['Content-Type' => 'application/json']]);
}

/**
 * The parts of an order body a quote fixes: each line's price and the totals.
 *
 * @return array<string, mixed>
 */
function websiteOrderFromQuote(TestResponse $quote): array
{
    $money = fn (array $value) => ['amount' => $value['amount'], 'currency' => $value['currency']];
    $data = $quote->json('data');

    return [
        'items' => array_map(fn (array $line) => [
            'sku' => $line['sku'],
            'quantity' => $line['quantity'],
            'unit_price' => $money($line['unit_price']),
        ], $data['items']),
        'totals' => [
            'subtotal' => $money($data['subtotal']),
            'discount' => $money($data['discount']),
            'shipping' => $money($data['shipping']),
            'tax' => $money($data['tax']),
            'grand_total' => $money($data['grand_total']),
        ],
    ];
}

describe('the checkout quote (P2 H1–H3)', function () {
    it('quotes exactly what order placement then accepts', function () {
        $quote = websiteOrderQuote()->assertOk();

        $quote->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.items.0.unit_price.amount', '2600.00')
            ->assertJsonPath('data.items.0.line_total.amount', '5200.00')
            ->assertJsonPath('data.subtotal.amount', '5200.00')
            ->assertJsonPath('data.shipping.amount', '60.00')
            ->assertJsonPath('data.tax.amount', '0.00')
            ->assertJsonPath('data.grand_total.amount', '5260.00')
            ->assertJsonPath('data.grand_total.minor_units', 526000)
            ->assertJsonPath('data.delivery.basis', 'flat')
            ->assertJsonPath('data.items.0.availability.reservable', true)
            ->assertJsonPath('data.payment.methods', ['online']);

        $body = websiteOrderBody(websiteOrderFromQuote($quote));

        websiteOrderSubmit(body: $body)->assertCreated();
    });

    it('writes nothing and holds no stock', function () {
        $before = [
            'orders' => websiteTestOrders()->count(),
            'payments' => Payment::query()->count(),
            'reservations' => StockReservation::query()->count(),
            'ledger' => LedgerEntry::query()->count(),
            'customers' => WebsiteCustomer::query()->count(),
            'available' => $this->stock->refresh()->available,
        ];

        websiteOrderQuote()->assertOk();
        websiteOrderQuote()->assertOk();

        expect([
            'orders' => websiteTestOrders()->count(),
            'payments' => Payment::query()->count(),
            'reservations' => StockReservation::query()->count(),
            'ledger' => LedgerEntry::query()->count(),
            'customers' => WebsiteCustomer::query()->count(),
            'available' => $this->stock->refresh()->available,
        ])->toBe($before);
    });

    it('is advisory: a price changed after the quote is refused at placement with the right figures', function () {
        $quote = websiteOrderQuote()->assertOk();

        $this->selection->forceFill(['price' => Money::fromDecimal('2700.00', Currency::BDT)])->save();

        websiteOrderSubmit(body: websiteOrderBody(websiteOrderFromQuote($quote)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'price_mismatch')
            ->assertJsonPath('error.details.authoritative.grand_total.amount', '5460.00');

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('is advisory across a flash deal: a window that closes after the quote is refused at placement', function () {
        $this->selection->forceFill([
            'promotional_price' => Money::fromDecimal('2200.00', Currency::BDT),
            'promotion_ends_at' => now()->addMinutes(5),
        ])->save();

        $quote = websiteOrderQuote()->assertOk()
            ->assertJsonPath('data.items.0.unit_price.amount', '2200.00')
            ->assertJsonPath('data.grand_total.amount', '4460.00');

        $this->travel(6)->minutes();

        websiteOrderSubmit(body: websiteOrderBody(websiteOrderFromQuote($quote)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'price_mismatch')
            ->assertJsonPath('error.details.authoritative.grand_total.amount', '5260.00');

        expect(websiteTestOrders()->count())->toBe(0);
    });

    it('says when the stock is gone, and placement refuses the same cart', function () {
        $quote = websiteOrderQuote()->assertOk();

        app(PartnerStock::class)->move($this->stock->refresh(), StockBucket::Available, null, 19, PartnerStockMovementType::Adjustment);

        websiteOrderQuote()->assertOk()->assertJsonPath('data.items.0.availability.reservable', false);

        websiteOrderSubmit(body: websiteOrderBody(websiteOrderFromQuote($quote)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_stock');
    });

    it('never counts another partner\'s units, or central stock, as the shop\'s own', function () {
        app(PartnerStock::class)->move($this->stock->refresh(), StockBucket::Available, null, 19, PartnerStockMovementType::Adjustment);
        websiteTestOwns(websiteTestAccount(), $this->selection->product, 500);
        $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->selection->product_id, 'available' => 500]);

        $quote = websiteOrderQuote()->assertOk()
            ->assertJsonPath('data.items.0.availability.quantity', 1)
            ->assertJsonPath('data.items.0.availability.reservable', false);

        websiteOrderSubmit(body: websiteOrderBody(websiteOrderFromQuote($quote)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_stock');
    });

    it('lets a Non-Conditional shop reserve from central stock, as placement does', function () {
        app(PartnerStock::class)->move($this->stock->refresh(), StockBucket::Available, null, 19, PartnerStockMovementType::Adjustment);
        $this->website->businessAccount->forceFill(['account_type' => AccountType::NonConditional])->save();
        $warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
        StockItem::create(['warehouse_id' => $warehouse->id, 'product_id' => $this->selection->product_id, 'available' => 500]);

        websiteOrderQuote()->assertOk()
            ->assertJsonPath('data.items.0.availability.in_stock', true)
            ->assertJsonPath('data.items.0.availability.reservable', true);
    });

    it('answers cash on delivery the way placement does', function () {
        websiteOrderQuote(['payment_method' => 'cod'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        $this->website->forceFill(['payment_config' => ['cod' => ['enabled' => true, 'maximum_amount' => '5000.00']]])->save();

        $quote = websiteOrderQuote(['payment_method' => 'cod'])->assertOk()
            ->assertJsonPath('data.payment.cod.enabled', true)
            ->assertJsonPath('data.payment.cod.eligible', false)
            ->assertJsonPath('data.payment.cod.maximum.amount', '5000.00');

        websiteOrderSubmit(body: websiteOrderBody([...websiteOrderFromQuote($quote), 'payment' => ['method' => 'cod']]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cod_limit_exceeded');
    });

    it('refuses what placement refuses', function (Closure $arrange, array $body, int $status, string $code) {
        $arrange($this);

        websiteOrderQuote($body)->assertStatus($status)->assertJsonPath('error.code', $code);
    })->with([
        'a coupon' => [fn () => null, ['coupon_code' => 'EID10'], 422, 'coupon_not_applicable'],
        'a website not taking orders' => [fn ($test) => $test->website->forceFill(['status' => WebsiteStatus::Maintenance])->save(), [], 409, 'website_not_accepting_orders'],
        'an unknown product' => [fn () => null, ['items' => [['sku' => 'NO-SUCH-SKU', 'quantity' => 1]]], 422, 'product_unavailable'],
        'too many of one product' => [fn ($test) => $test->selection->product->forceFill(['max_order_quantity' => 1])->save(), [], 422, 'quantity_not_allowed'],
        'the same product twice' => [fn () => null, ['items' => [['sku' => 'A1', 'quantity' => 1], ['sku' => 'a1', 'quantity' => 1]]], 422, 'validation_failed'],
        'no payment method' => [fn () => null, ['payment_method' => ''], 422, 'validation_failed'],
    ]);

    it('never prices another website\'s product', function () {
        $other = Website::factory()->forAccount(websiteTestAccount(extra: [PackageFeature::DropshippingEnabled->value => '1']))->active()->create();
        $theirs = websiteOrderSelection($other, 100000);

        websiteOrderQuote(['items' => [['sku' => $theirs->product->sku, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'product_unavailable');
    });

    it('needs the checkout scope', function () {
        $credential = storefrontCredential($this->website, [CredentialScope::CatalogRead]);

        websiteOrderQuote(credential: $credential)->assertForbidden();
    });
});

describe('delivery areas (P2 H4)', function () {
    beforeEach(function () {
        $this->division = BdLocation::create(['type' => BdLocationType::Division, 'source_id' => '3', 'name_en' => 'Dhaka', 'name_bn' => 'ঢাকা', 'is_active' => true]);
        $this->district = BdLocation::create(['type' => BdLocationType::District, 'source_id' => '26', 'source_parent_id' => '3', 'parent_id' => $this->division->id, 'name_en' => 'Dhaka', 'name_bn' => 'ঢাকা', 'is_active' => true]);
        BdLocation::create(['type' => BdLocationType::District, 'source_id' => '27', 'source_parent_id' => '3', 'parent_id' => $this->division->id, 'name_en' => 'Gazipur', 'name_bn' => 'গাজীপুর', 'is_active' => false]);
    });

    it('walks the directory by stable identifiers, never database ids', function () {
        $credential = storefrontCredential($this->website, [CredentialScope::CatalogRead]);

        $divisions = storefrontCall($credential[0], $credential[1], 'delivery/areas')->assertOk();

        $divisions->assertJsonPath('data.0.id', 'division:-:3')
            ->assertJsonPath('data.0.type', 'division')
            ->assertJsonPath('data.0.name.bn', 'ঢাকা')
            ->assertJsonPath('data.0.has_children', true);

        expect($divisions->getContent())->not->toContain('"id":'.$this->division->id.',');

        storefrontCall($credential[0], $credential[1], 'delivery/areas', ['parent' => 'division:-:3'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'district:3:26')
            ->assertJsonPath('data.0.has_children', false);

        storefrontCall($credential[0], $credential[1], 'delivery/areas', ['parent' => 'district:9:999'])->assertNotFound();
        storefrontCall($credential[0], $credential[1], 'delivery/areas', ['parent' => 'nonsense'])->assertNotFound();
    });

    it('accepts a known area on a quote and an order, and refuses an unknown one', function () {
        websiteOrderQuote(['delivery' => ['area_id' => 'district:3:26']])->assertOk()
            ->assertJsonPath('data.delivery.area_id', 'district:3:26')
            ->assertJsonPath('data.shipping.amount', '60.00');

        websiteOrderQuote(['delivery' => ['area_id' => 'district:3:27']])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        websiteOrderSubmit(['shipping_address' => ['area_id' => 'district:3:26']])->assertCreated();

        expect(websiteTestOrders()->sole()->shipping_address['area_id'] ?? null)->toBe('district:3:26');

        websiteOrderSubmit(['storefront_order_reference' => 'SF-2', 'shipping_address' => ['area_id' => 'nowhere']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    });
});
