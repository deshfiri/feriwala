<?php

use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\ExpireUnconfirmedCodOrders;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Cash on delivery, from intake to the code that confirms it
 * (contract §6.1.2, §6.2, §18.4, §28, P6-10).
 *
 * The ERP decides whether a shop may take it, the customer confirms with a code
 * sent to their own number, and nothing here is paid: the money is collected on
 * delivery and settled by §28, which is a later phase.
 */
beforeEach(function () {
    // The SMS goes through the same provider contract everything else uses,
    // read off the message the way the customer reads it.
    $this->sent = collect();

    app()->instance(SmsProvider::class, new class($this->sent) implements SmsProvider
    {
        public function __construct(private Collection $sent) {}

        public function send(SmsMessage $message): SmsResult
        {
            $this->sent->push($message);

            return SmsResult::accepted('test');
        }

        public function balance(): ?string
        {
            return null;
        }

        public function name(): string
        {
            return 'spy';
        }
    });

    $settings = app(SettingsRepository::class);
    $settings->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->website, [
        CredentialScope::OrdersWrite, CredentialScope::OrdersRead,
    ]);

    // An endpoint, so what the shop is told can be seen.
    app(ManageWebhookEndpoint::class)
        ->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->selection = codTestSelection($this->website);
    $this->stock = codTestStock($this->selection, 20);
    websiteTestFee(FeeType::WebsiteDelivery, 6000);
});

function codTestSelection(Website $website): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 260000,
        'published_at' => now(),
    ]);
}

function codTestStock(WebsiteProduct $selection, int $units): StockItem
{
    $item = StockItem::create(['warehouse_id' => test()->warehouse->id, 'product_id' => $selection->product_id]);
    app(StockLedger::class)->move($item, null, StockBucket::Available, $units, StockMovementType::Adjustment);

    return $item;
}

/**
 * A cash-on-delivery order as a storefront submits one.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function codTestBody(array $overrides = []): array
{
    $money = fn (int $minor) => ['minor_units' => $minor, 'currency' => 'BDT'];

    return array_replace_recursive([
        'storefront_order_reference' => 'SF-COD-'.Str::upper(Str::random(5)),
        'customer' => ['name' => 'Ayesha Rahman', 'phone' => '01712-345678', 'is_guest' => true],
        'shipping_address' => ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'],
        'items' => [['sku' => test()->selection->product->sku, 'quantity' => 2, 'unit_price' => $money(260000)]],
        'totals' => [
            'subtotal' => $money(520000),
            'discount' => $money(0),
            'shipping' => $money(6000),
            'tax' => $money(0),
            'grand_total' => $money(526000),
        ],
        'payment' => ['method' => 'cod'],
    ], $overrides);
}

function codTestSubmit(array $overrides = [], ?string $key = null): TestResponse
{
    return storefrontCall(
        test()->credential,
        test()->secret,
        'orders',
        method: 'POST',
        body: (string) json_encode(codTestBody($overrides)),
        overrides: ['headers' => ['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Content-Type' => 'application/json']],
    );
}

function codTestPost(string $path, array $body = []): TestResponse
{
    return storefrontCall(
        test()->credential,
        test()->secret,
        $path,
        method: 'POST',
        body: $body === [] ? '' : (string) json_encode($body),
        overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']],
    );
}

/**
 * The code as it was sent, read out of the message rather than the store.
 *
 * Six digits standing on their own: the order reference in the same message
 * carries a six-digit date, and reading that instead would test nothing.
 */
function codTestCode(): string
{
    $body = (string) (test()->sent->last()->body ?? '');

    expect($body)->toMatch('/(?<![\d-])\d{6}(?![\d-])/');
    preg_match_all('/(?<![\d-])(\d{6})(?![\d-])/', $body, $matches);

    return (string) end($matches[1]);
}

describe('taking a cash-on-delivery order', function () {
    it('takes it, holds the stock for the confirmation window and asks the customer to confirm', function () {
        $response = codTestSubmit();

        $response->assertCreated()
            ->assertJsonPath('status', OrderStatus::CustomerVerificationPending->value)
            ->assertJsonPath('payment.method', 'cod')
            ->assertJsonPath('payment.redirect_url', null)
            ->assertJsonPath('confirmation.state', 'pending');

        $order = Order::query()->sole();

        expect($order->status)->toBe(OrderStatus::CustomerVerificationPending)
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Active)
            ->and($order->items()->sole()->stockReservation->kind->value)->toBe('cod')
            // A day to confirm, not the fifteen minutes an online payment has.
            ->and(now()->diffInHours($order->items()->sole()->stockReservation->expires_at))->toBeGreaterThan(20)
            ->and($order->payment->gateway)->toBeNull()
            ->and($order->payment->status)->toBe(PaymentStatus::Draft)
            ->and($order->payment->invoice()->exists())->toBeFalse()
            ->and($this->stock->refresh()->reserved)->toBe(2);

        // The code went to the customer's own number, and to nothing else.
        expect($this->sent)->toHaveCount(1)
            ->and($this->sent->first()->to)->toBe('+8801712345678')
            ->and($this->sent->first()->event)->toBe('cod_confirmation');
    });

    it('refuses cash on delivery where the shop may not take it', function () {
        app(SettingsRepository::class)->set(CodTerms::ENABLED, false);

        codTestSubmit()
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'payment_method_unavailable');

        expect(Order::query()->count())->toBe(0);

        // A shop may be switched on even where the platform default is off.
        $this->website->forceFill(['payment_config' => ['cod' => ['enabled' => true]]])->save();

        codTestSubmit()->assertCreated();
    });

    it('refuses an order above what may be taken on delivery', function () {
        $this->website->forceFill(['payment_config' => ['cod' => ['maximum_minor' => 500000]]])->save();

        codTestSubmit()
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cod_limit_exceeded')
            ->assertJsonPath('error.details.maximum.minor_units', 500000);

        expect(Order::query()->count())->toBe(0);
    });

    it('prices and stocks it exactly as any other order', function () {
        codTestSubmit(['totals' => ['grand_total' => ['minor_units' => 999]]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'price_mismatch');

        codTestSubmit(['customer' => ['phone' => 'nonsense']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mobile_number');

        codTestSubmit([
            'items' => [['sku' => $this->selection->product->sku, 'quantity' => 50, 'unit_price' => ['minor_units' => 260000, 'currency' => 'BDT']]],
            'totals' => ['subtotal' => ['minor_units' => 13000000], 'grand_total' => ['minor_units' => 13006000]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_stock');

        expect(Order::query()->count())->toBe(0);
    });
});

describe('the code that confirms it (§6.2)', function () {
    beforeEach(function () {
        codTestSubmit()->assertCreated();
        $this->order = Order::query()->sole();
    });

    it('is held hashed, never in the open, and confirms the order exactly once', function () {
        $code = codTestCode();

        // Nothing readable is stored: the store holds a hash of it.
        $stored = Cache::get(sprintf('verification:%s:%s', SendCodConfirmationCode::PURPOSE, hash('sha256', $this->order->public_id)));

        expect($stored)->not->toBeNull()
            ->and(json_encode($stored))->not->toContain($code)
            ->and($stored['hash'])->not->toBe($code);

        $first = codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => $code]);

        $first->assertOk()
            ->assertJsonPath('status', OrderStatus::Confirmed->value)
            ->assertJsonPath('confirmation.state', 'confirmed');

        $this->order->refresh();

        expect($this->order->status)->toBe(OrderStatus::Confirmed)
            // Confirming commits the stock and pays nothing (§28).
            ->and($this->order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Committed)
            ->and($this->order->payment->refresh()->status)->toBe(PaymentStatus::Draft)
            ->and($this->order->payment->invoice()->exists())->toBeFalse()
            ->and($this->stock->refresh()->processing)->toBe(2);

        // One status entry, one webhook, whatever arrives afterwards.
        $again = codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => $code]);

        $again->assertOk()->assertJsonPath('status', OrderStatus::Confirmed->value);

        expect($this->order->statusHistory()->where('new_status', OrderStatus::Confirmed->value)->count())->toBe(1)
            ->and(WebhookDelivery::query()->where('event_type', WebhookEvent::OrderStatusChanged->value)->count())->toBe(1);
    });

    it('never carries the code back to the storefront', function () {
        $code = codTestCode();

        $read = storefrontCall($this->credential, $this->secret, 'orders/'.$this->order->public_id)->assertOk();

        $read->assertJsonPath('confirmation.state', 'pending');

        expect($read->getContent())->not->toContain($code)
            ->and($read->getContent())->not->toContain('attempts')
            ->and($read->json('confirmation'))->toHaveKeys(['state', 'expires_at', 'resend_available_in']);
    });

    it('refuses a wrong code, and says the same thing when there is none', function () {
        codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'confirmation_refused');

        expect($this->order->refresh()->status)->toBe(OrderStatus::CustomerVerificationPending);

        // Spent guesses destroy the code; the answer does not change.
        for ($attempt = 0; $attempt < VerificationCodes::MAX_ATTEMPTS; $attempt++) {
            codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => '111111']);
        }

        codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => codTestCode()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'confirmation_refused');

        expect($this->order->refresh()->status)->toBe(OrderStatus::CustomerVerificationPending);
    });

    it('holds a resend to its cooldown', function () {
        codTestPost('orders/'.$this->order->public_id.'/confirmation/code')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'confirmation_code_too_soon');

        expect($this->sent)->toHaveCount(1);

        $this->travelTo(now()->addSeconds(VerificationCodes::RESEND_COOLDOWN_SECONDS + 1));

        codTestPost('orders/'.$this->order->public_id.'/confirmation/code')->assertStatus(202);

        expect($this->sent)->toHaveCount(2);

        // The code that arrives last is the one that works.
        $response = codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => codTestCode()]);

        $response->assertOk();
    });

    it('confirms nobody else\'s order', function () {
        $other = Website::factory()->forAccount($this->account)->active()->create();
        [$credential, $secret] = storefrontCredential($other, [CredentialScope::OrdersWrite, CredentialScope::OrdersRead]);

        storefrontCall($credential, $secret, 'orders/'.$this->order->public_id.'/confirmation', method: 'POST', body: (string) json_encode([
            'code' => codTestCode(),
        ]), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])
            ->assertNotFound();

        expect($this->order->refresh()->status)->toBe(OrderStatus::CustomerVerificationPending);
    });

    it('confirms nothing once the order has been cancelled', function () {
        $code = codTestCode();

        codTestPost('orders/'.$this->order->public_id.'/cancellation')->assertOk();

        expect($this->order->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($this->order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Released);

        codTestPost('orders/'.$this->order->public_id.'/confirmation', ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_awaiting_confirmation');
    });
});

describe('when the window closes', function () {
    it('cancels the order, gives the stock back once and refuses a later code', function () {
        codTestSubmit()->assertCreated();
        $order = Order::query()->sole();
        $code = codTestCode();

        $this->travelTo($order->payment->expires_at->addMinute());

        expect(app(ExpireUnconfirmedCodOrders::class)->handle())->toBe(['expired' => 1]);

        $order->refresh();

        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->cancellation_reason)->toBe('The time to confirm the order ran out.')
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Released)
            ->and($this->stock->refresh()->reserved)->toBe(0)
            ->and($this->stock->available)->toBe(20);

        // A second pass changes nothing.
        expect(app(ExpireUnconfirmedCodOrders::class)->handle())->toBe(['expired' => 0])
            ->and($order->statusHistory()->where('new_status', OrderStatus::Cancelled->value)->count())->toBe(1);

        codTestPost('orders/'.$order->public_id.'/confirmation', ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order_not_awaiting_confirmation');
    });

    it('refuses a confirmation whose stock deadline has passed, even before the sweep runs', function () {
        codTestSubmit()->assertCreated();
        $order = Order::query()->sole();
        $code = codTestCode();

        $this->travelTo($order->items()->sole()->stockReservation->expires_at->addMinute());

        codTestPost('orders/'.$order->public_id.'/confirmation', ['code' => $code])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'confirmation_expired');

        expect($order->refresh()->status)->toBe(OrderStatus::CustomerVerificationPending);
    });
});
