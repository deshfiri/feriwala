<?php

use App\Domain\Account\Enums\AccountType;
use App\Domain\Account\Models\BusinessAccount;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\RecordCodCollection;
use App\Domain\Order\Actions\SynchronizeOrderProceedsWithDelivery;
use App\Domain\Order\Enums\OrderDeliveryStatus;
use App\Domain\Order\Models\OrderProceedsSettlement;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\CredentialScope;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A Non-Conditional account sells straight from the catalogue: it need not buy a
 * product to publish it, the order is filled from central stock, and Feriwala
 * recovers the product cost from the sale's proceeds. A Conditional account is
 * unchanged — it must buy first.
 */
beforeEach(function () {
    app(SettingsRepository::class)->define(CodTerms::ENABLED, 'orders', SettingType::Boolean, true);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->api = storefrontCredential($this->website, [
        CredentialScope::CatalogRead, CredentialScope::InventoryRead, CredentialScope::OrdersWrite, CredentialScope::OrdersRead,
    ]);
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/feriwala/webhooks');

    $this->warehouse = Warehouse::create(['code' => 'DHK', 'name' => 'Dhaka', 'is_default' => true]);
    $this->product = websiteTestProduct();
    websiteTestFee(FeeType::WebsiteDelivery, 6000);
});

function nonConditionalMake(BusinessAccount $account): void
{
    $account->forceFill(['account_type' => AccountType::NonConditional])->save();
}

function nonConditionalCentralStock(int $units): StockItem
{
    return StockItem::create([
        'warehouse_id' => test()->warehouse->id,
        'product_id' => test()->product->id,
        'available' => $units,
    ]);
}

function nonConditionalPublish(): TestResponse
{
    return test()->actingAs(test()->account->owner)
        ->put(route('catalog.mine.websites.update', [test()->product->slug, test()->website->public_id]), ['published' => true]);
}

function nonConditionalPlaceOrder(): TestResponse
{
    $money = fn (string $amount) => ['amount' => $amount, 'currency' => 'BDT'];

    $body = [
        'storefront_order_reference' => 'SF-NC-'.Str::upper(Str::random(5)),
        'customer' => ['name' => 'Ayesha Rahman', 'phone' => '01712-345678', 'is_guest' => true],
        'shipping_address' => ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'],
        'items' => [['sku' => test()->product->sku, 'quantity' => 2, 'unit_price' => $money('2500.00')]],
        'totals' => [
            'subtotal' => $money('5000.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('60.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('5060.00'),
        ],
        'payment' => ['method' => 'cod'],
    ];

    return storefrontCall(
        test()->api[0],
        test()->api[1],
        'orders',
        method: 'POST',
        body: (string) json_encode($body),
        overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']],
    );
}

describe('publishing without buying', function () {
    it('lets a Non-Conditional account publish a product it never bought and shows central stock', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);

        nonConditionalPublish()->assertSessionHasNoErrors();

        expect(WebsiteProduct::query()->sole()->status)->toBe(WebsiteProductStatus::Published);

        storefrontCall($this->api[0], $this->api[1], 'products')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->product->public_id)
            ->assertJsonPath('data.0.variants.0.availability.quantity', 20);
    });

    it('still has nothing to publish when central stock is empty', function () {
        nonConditionalMake($this->account);

        nonConditionalPublish()->assertSessionHasErrors('website');

        expect(WebsiteProduct::query()->where('status', WebsiteProductStatus::Published)->count())->toBe(0);
    });

    it('keeps a Conditional account bound to what it bought, whatever central stock holds', function () {
        nonConditionalCentralStock(20);

        nonConditionalPublish()->assertSessionHasErrors('website');

        expect(WebsiteProduct::query()->count())->toBe(0);
    });
});

describe('selling it', function () {
    it('fills the order from central stock and snapshots what Feriwala is owed', function () {
        nonConditionalMake($this->account);
        $central = nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();

        nonConditionalPlaceOrder()->assertCreated();

        $item = websiteTestOrders()->sole()->items()->sole();

        expect($item->stockReservation->isPartnerSourced())->toBeFalse()
            ->and($item->product_cost?->toDecimal())->toBe('3000.00')
            ->and($central->refresh()->available)->toBe(18);
    });

    it('sells the account\'s own units first and recovers no cost for them', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        websiteTestOwns($this->account, $this->product, 20);
        nonConditionalPublish()->assertSessionHasNoErrors();

        nonConditionalPlaceOrder()->assertCreated();

        $item = websiteTestOrders()->sole()->items()->sole();

        expect($item->stockReservation->isPartnerSourced())->toBeTrue()
            ->and($item->product_cost)->toBeNull();
    });

    it('credits the reseller only what the collected cash leaves after the product cost', function () {
        nonConditionalMake($this->account);
        nonConditionalCentralStock(20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPlaceOrder()->assertCreated();

        $order = websiteTestOrders()->sole();
        $item = $order->items()->sole();

        app(SynchronizeOrderProceedsWithDelivery::class)->handle($order->load('items'), OrderDeliveryStatus::Delivered);
        app(RecordCodCollection::class)->handle($this->account->owner, $item, Money::fromDecimal('5000.00', Currency::BDT));

        $settlement = OrderProceedsSettlement::query()->where('order_item_id', $item->id)->sole();

        expect($settlement->recovered_amount->toDecimal())->toBe('3000.00')
            ->and($settlement->reseller_earning?->toDecimal())->toBe('2000.00')
            ->and($settlement->flagged_for_review)->toBeFalse();
    });

    it('refuses a collection on a line that deferred no cost', function () {
        nonConditionalMake($this->account);
        websiteTestOwns($this->account, $this->product, 20);
        nonConditionalPublish()->assertSessionHasNoErrors();
        nonConditionalPlaceOrder()->assertCreated();

        $item = websiteTestOrders()->sole()->items()->sole();

        expect(fn () => app(RecordCodCollection::class)->handle($this->account->owner, $item, Money::fromDecimal('5000.00', Currency::BDT)))
            ->toThrow(InvalidArgumentException::class);
    });
});
