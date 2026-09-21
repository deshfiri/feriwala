<?php

use App\Domain\Account\VerificationCodes;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Enums\StockBucket;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\StockLedger;
use App\Domain\Order\Actions\ConfirmCodOrder;
use App\Domain\Order\Actions\PlaceWebsiteOrder;
use App\Domain\Order\Actions\SendCodConfirmationCode;
use App\Domain\Order\Data\WebsiteOrderSubmission;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\OrderStatusChangeSource;
use App\Domain\Order\Enums\ReturnStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderReturn;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Data\WebsiteCustomerDetails;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\StatusHistory\StatusChange;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A customer asking, through their shop, to send something back (contract
 * §6.3, P6-12).
 *
 * A request only: the storefront can ask and read back where it stands; it
 * can never move stock or money.
 */
beforeEach(function () {
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    [$this->credential, $this->secret] = storefrontCredential($this->website, [
        CredentialScope::OrdersRead, CredentialScope::ReturnsWrite,
    ]);

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    websiteTestFee(FeeType::WebsiteDelivery, 0);

    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price_minor' => 260000,
        'published_at' => now(),
    ]);

    $this->stock = StockItem::create(['warehouse_id' => $this->warehouse->id, 'product_id' => $this->selection->product_id]);
    app(StockLedger::class)->move($this->stock, null, StockBucket::Available, 10, StockMovementType::Adjustment);
});

/**
 * Two panjabis, confirmed on delivery and delivered — the part fulfilment will
 * do once it exists (§20, P6-17).
 */
function returnApiDeliveredOrder(bool $deliver = true): Order
{
    $money = fn (int $minor) => Money::of($minor, Currency::BDT);
    $address = ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'];

    [$order] = app(PlaceWebsiteOrder::class)->handle(test()->website, new WebsiteOrderSubmission(
        reference: 'SF-RA-'.Str::upper(Str::random(6)),
        idempotencyKey: (string) Str::uuid(),
        customer: new WebsiteCustomerDetails(name: 'Ayesha Rahman', mobile: '+88017'.random_int(10000000, 99999999)),
        shippingAddress: $address,
        billingAddress: $address,
        items: [['sku' => test()->selection->product->sku, 'quantity' => 2]],
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

    $code = app(VerificationCodes::class)->issue(SendCodConfirmationCode::PURPOSE, $order->public_id);
    app(ConfirmCodOrder::class)->handle($order, $code);

    if (! $deliver) {
        return $order->refresh();
    }

    $order->refresh();

    foreach ([
        OrderStatus::ReadyForFulfillment, OrderStatus::Picking, OrderStatus::Packing,
        OrderStatus::ReadyForPickup, OrderStatus::CourierAssigned, OrderStatus::Shipped, OrderStatus::Delivered,
    ] as $status) {
        $order->moveTo($status, new StatusChange(reason: 'Moved by the test, as fulfilment will.'), OrderStatusChangeSource::System);
    }

    app(StockLedger::class)->move(test()->stock, StockBucket::Processing, StockBucket::Sold, 2, StockMovementType::Adjustment);

    return $order->refresh();
}

/**
 * @param  array<string, mixed>  $body
 */
function returnApiAsk(Order $order, array $body = [], ?string $key = null, ?array $credential = null): TestResponse
{
    [$record, $secret] = $credential ?? [test()->credential, test()->secret];

    return storefrontCall($record, $secret, 'orders/'.$order->public_id.'/return-requests', method: 'POST', body: (string) json_encode([
        'reason' => 'not_as_described',
        'lines' => [['sku' => $order->items()->sole()->sku, 'quantity' => 1]],
        'customer_note' => 'The colour is not what the picture showed.',
        ...$body,
    ]), overrides: ['headers' => ['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Content-Type' => 'application/json']]);
}

it('takes a return request and moves nothing', function () {
    $order = returnApiDeliveredOrder();
    $before = $this->stock->refresh()->buckets();

    $response = returnApiAsk($order);

    $response->assertCreated()
        ->assertJsonPath('status', ReturnStatus::Requested->value)
        ->assertJsonPath('lines.0.quantity', 1)
        ->assertJsonPath('lines.0.approved_quantity', null)
        ->assertJsonPath('refund.state', 'not_required');

    expect(OrderReturn::query()->sole()->source)->toBe(OrderStatusChangeSource::Storefront)
        ->and($this->stock->refresh()->buckets())->toBe($before);

    // Nothing of how Feriwala keeps its stock reaches the shop.
    expect($response->getContent())->not->toContain('warehouse')
        ->and($response->getContent())->not->toContain('disposition');
});

it('answers the same request twice with one return', function () {
    $order = returnApiDeliveredOrder();

    $first = returnApiAsk($order, key: 'return-1');
    $second = returnApiAsk($order, key: 'return-1');

    expect($second->getContent())->toBe($first->getContent())
        ->and(OrderReturn::query()->count())->toBe(1);
});

it('says what can still be sent back, and what has been asked for, when the order is read', function () {
    $order = returnApiDeliveredOrder();
    returnApiAsk($order)->assertCreated();

    storefrontCall($this->credential, $this->secret, 'orders/'.$order->public_id)
        ->assertOk()
        ->assertJsonPath('returnable.eligible', true)
        ->assertJsonPath('returnable.lines.0.returnable', 1)
        ->assertJsonPath('returns.0.status', ReturnStatus::Requested->value)
        ->assertJsonPath('returns.0.timeline.0.note', 'Return requested. We will let you know once it has been reviewed.');
});

it('refuses an order nothing has been delivered from, and more than is left', function () {
    returnApiAsk(returnApiDeliveredOrder(deliver: false))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'order_not_returnable');

    returnApiAsk(returnApiDeliveredOrder(), ['lines' => [['sku' => $this->selection->product->sku, 'quantity' => 3]]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'quantity_not_returnable')
        ->assertJsonPath('error.details.returnable', 2);

    returnApiAsk(returnApiDeliveredOrder(), ['reason' => 'because'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OrderReturn::query()->count())->toBe(0);
});

it('finds no order of another shop, and refuses a credential without the scope', function () {
    $order = returnApiDeliveredOrder();

    $other = Website::factory()->forAccount($this->account)->active()->create();
    $theirs = storefrontCredential($other, [CredentialScope::ReturnsWrite]);

    returnApiAsk($order, credential: $theirs)->assertNotFound();

    $readOnly = storefrontCredential($this->website, [CredentialScope::OrdersRead]);

    returnApiAsk($order, credential: $readOnly)->assertForbidden();

    expect(OrderReturn::query()->count())->toBe(0);
});
