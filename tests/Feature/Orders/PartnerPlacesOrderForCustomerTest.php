<?php

use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Enums\OrderSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Integrations\Sms\Contracts\SmsProvider;
use App\Integrations\Sms\Data\SmsMessage;
use App\Integrations\Sms\Data\SmsResult;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A Partner takes an order for their own customer from Store Orders: priced by
 * the ERP, cash on delivery, confirmed with the code the customer is sent, and
 * never reaching another Partner's storefront.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->sms = collect();

    app()->instance(SmsProvider::class, new class($this->sms) implements SmsProvider
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

        public function isConfigured(): bool
        {
            return true;
        }

        public function requiredConfiguration(): array
        {
            return [];
        }

        public function missingConfiguration(): array
        {
            return [];
        }
    });

    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);
    Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    websiteTestFee(FeeType::WebsiteDelivery, 6000);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->partner = $this->account->owner;
    $this->website = Website::factory()->forAccount($this->account)->active()->create(['name' => 'Alpha shop']);
    $this->selection = partnerOrderTestSelection($this->website);
    websiteTestOwnsSelection($this->selection, 20);
});

function partnerOrderTestSelection(Website $website): WebsiteProduct
{
    return WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $website->business_account_id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function partnerOrderTestBody(array $overrides = []): array
{
    return array_replace([
        'storefront' => test()->website->public_id,
        'token' => (string) Str::uuid(),
        'customer_name' => 'Ayesha Rahman',
        'customer_phone' => '01712-345678',
        'line1' => 'House 12, Road 3',
        'city' => 'Dhaka',
        'items' => [['sku' => test()->selection->product->sku, 'quantity' => 2]],
    ], $overrides);
}

/** The storefront orders only: owning stock in the setup is itself a wholesale order. */
function partnerOrderTestOrders(): Builder
{
    return Order::query()->where('source', OrderSource::Website);
}

function partnerOrderTestCode(): string
{
    $body = (string) (test()->sms->last()->body ?? '');

    preg_match_all('/(?<![\d-])(\d{6})(?![\d-])/', $body, $matches);

    expect($matches[1])->not->toBeEmpty();

    return (string) end($matches[1]);
}

describe('placing the order', function () {
    it('takes a cash-on-delivery order at the ERP\'s own price and holds the stock', function () {
        $response = $this->actingAs($this->partner)->post(route('store-orders.store'), partnerOrderTestBody());

        $order = partnerOrderTestOrders()->sole();

        $response->assertRedirect(route('store-orders.show', $order->public_id));

        expect($order->source)->toBe(OrderSource::Website)
            ->and($order->status)->toBe(OrderStatus::CustomerVerificationPending)
            ->and($order->business_account_id)->toBe($this->account->id)
            ->and($order->website_id)->toBe($this->website->id)
            ->and($order->total->toDecimal())->toBe('5260.00')
            ->and($order->customer['mobile'])->toBe('+8801712345678')
            ->and($order->shipping_address['city'])->toBe('Dhaka')
            ->and($order->items()->count())->toBe(1)
            ->and($order->items()->sole()->stock_reservation_id)->not->toBeNull();
    });

    it('ignores any price the browser sends', function () {
        $this->actingAs($this->partner)->post(route('store-orders.store'), partnerOrderTestBody([
            'unit_price' => '1.00', 'total' => '1.00',
            'items' => [['sku' => $this->selection->product->sku, 'quantity' => 2, 'unit_price' => '1.00']],
        ]))->assertRedirect();

        expect(partnerOrderTestOrders()->sole()->total->toDecimal())->toBe('5260.00');
    });

    it('is one order however many times the same form is sent', function () {
        $body = partnerOrderTestBody();

        $this->actingAs($this->partner)->post(route('store-orders.store'), $body);
        $this->actingAs($this->partner)->post(route('store-orders.store'), $body);

        expect(partnerOrderTestOrders()->count())->toBe(1);
    });

    it('refuses a product that is not on sale, with the reason, and makes no order', function () {
        $this->actingAs($this->partner)->post(route('store-orders.store'), partnerOrderTestBody([
            'items' => [['sku' => 'NOT-A-SKU', 'quantity' => 1]],
        ]))->assertSessionHasErrors('items');

        expect(partnerOrderTestOrders()->count())->toBe(0);
    });

    it('refuses a mobile number that cannot be resolved', function () {
        $this->actingAs($this->partner)->post(route('store-orders.store'), partnerOrderTestBody([
            'customer_phone' => '12345',
        ]))->assertSessionHasErrors('customer_phone');

        expect(partnerOrderTestOrders()->count())->toBe(0);
    });

    it('needs the customer, an address and at least one product', function () {
        $this->actingAs($this->partner)->post(route('store-orders.store'), [
            'storefront' => $this->website->public_id, 'token' => (string) Str::uuid(),
        ])->assertSessionHasErrors(['customer_name', 'customer_phone', 'line1', 'city', 'items']);
    });
});

describe('what the form is told', function () {
    it('lists the storefront\'s products and quotes the price on the server', function () {
        $this->actingAs($this->partner)->getJson(route('store-orders.products', ['storefront' => $this->website->public_id]))
            ->assertOk()
            ->assertJsonPath('data.0.sku', $this->selection->product->sku)
            ->assertJsonPath('data.0.price.amount', '2600.00');

        $this->actingAs($this->partner)->postJson(route('store-orders.quote'), [
            'storefront' => $this->website->public_id,
            'items' => [['sku' => $this->selection->product->sku, 'quantity' => 2]],
            'total' => '1.00',
        ])->assertOk()
            ->assertJsonPath('data.subtotal.amount', '5200.00')
            ->assertJsonPath('data.delivery.amount', '60.00')
            ->assertJsonPath('data.total.amount', '5260.00');
    });

    it('puts the same button on the Partner\'s main Orders list', function () {
        $this->actingAs($this->partner)->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('orders/index')
                ->has('creatable_storefronts', 1)
                ->where('creatable_storefronts.0.value', $this->website->public_id)
                ->where('create_urls.store', route('store-orders.store')));
    });

    it('offers the button only for storefronts the person may run', function () {
        $this->actingAs($this->partner)->get(route('store-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('store-orders/index')
                ->has('creatable_storefronts', 1)
                ->where('creatable_storefronts.0.value', $this->website->public_id));
    });
});

describe('keeping to the Partner\'s own storefronts', function () {
    it('is a 404 on another Partner\'s storefront, for the picker, the price and the order', function () {
        $stranger = websiteTestAccount(extra: [
            PackageFeature::DropshippingEnabled->value => '1',
            PackageFeature::ProductPublishLimit->value => null,
        ])->owner;

        $this->actingAs($stranger)->getJson(route('store-orders.products', ['storefront' => $this->website->public_id]))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('store-orders.quote'), [
            'storefront' => $this->website->public_id,
            'items' => [['sku' => $this->selection->product->sku, 'quantity' => 1]],
        ])->assertNotFound();
        $this->actingAs($stranger)->post(route('store-orders.store'), partnerOrderTestBody())->assertNotFound();

        expect(partnerOrderTestOrders()->count())->toBe(0);
    });
});

describe('confirming with the customer\'s code', function () {
    it('confirms the order with the code the customer was sent, and refuses a wrong one', function () {
        $this->actingAs($this->partner)->post(route('store-orders.store'), partnerOrderTestBody());
        $order = partnerOrderTestOrders()->sole();

        $this->actingAs($this->partner)->get(route('store-orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.confirm_with_code', true));

        $this->actingAs($this->partner)->post(route('store-orders.confirm', $order->public_id), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        expect($order->refresh()->status)->toBe(OrderStatus::CustomerVerificationPending);

        $this->actingAs($this->partner)->post(route('store-orders.confirm', $order->public_id), ['code' => partnerOrderTestCode()])
            ->assertSessionHasNoErrors();

        expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);

        $this->actingAs($this->partner)->get(route('store-orders.show', $order->public_id))
            ->assertInertia(fn (Assert $page) => $page->where('can.confirm_with_code', false));
    });

    it('is a 404 for another Partner\'s order', function () {
        $this->actingAs($this->partner)->post(route('store-orders.store'), partnerOrderTestBody());
        $order = partnerOrderTestOrders()->sole();

        $stranger = websiteTestAccount()->owner;

        $this->actingAs($stranger)->post(route('store-orders.confirm', $order->public_id), ['code' => partnerOrderTestCode()])->assertNotFound();
        $this->actingAs($stranger)->post(route('store-orders.resend-code', $order->public_id))->assertNotFound();
        expect($order->refresh()->status)->toBe(OrderStatus::CustomerVerificationPending);
    });
});
