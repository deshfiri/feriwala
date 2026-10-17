<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Order\Queries\PriceWebsiteOrder;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\AnnouncePromotionWindows;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteProductPriceRule;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Flash deals: a window on a selection's promotional price (P2 H6, C4).
 *
 * The owner sets it within the administrator's limits; order pricing and the
 * storefront's catalogue charge the same price at every instant; and each edge
 * of the window is announced to the storefront exactly once.
 */
beforeEach(function () {
    websiteTestEveryoneBought();

    Http::fake();
    CarbonImmutable::setTestNow('2026-10-12 12:00:00');

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create(['domain' => 'shop.test']);
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');

    $this->selection = WebsiteProduct::create([
        'website_id' => $this->website->id,
        'business_account_id' => $this->account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Synced,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]);

    websiteTestFee(FeeType::WebsiteDelivery, 6000);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * The owner saving the selection through the ERP.
 *
 * @param  array<string, mixed>  $fields
 */
function promotionWindowSave(array $fields, ?Website $website = null, mixed $actor = null): TestResponse
{
    $website ??= test()->website;

    return test()->actingAs($actor ?? test()->account->owner)
        ->from(route('websites.products.index', $website->public_id))
        ->patch(route('websites.products.update', [$website->public_id, test()->selection->public_id]), $fields);
}

/**
 * How many `product.price_changed` events this website has been sent.
 */
function promotionWindowAnnouncements(): int
{
    return WebhookDelivery::query()
        ->where('website_id', test()->website->id)
        ->where('event_type', WebhookEvent::ProductPriceChanged->value)
        ->count();
}

describe('setting a window', function () {
    it('stores the instant the owner meant, in UTC, whatever offset it arrived in', function () {
        promotionWindowSave([
            'promotional_price' => '2200.00',
            'promotion_starts_at' => '2026-10-13T10:00:00+06:00',
            'promotion_ends_at' => '2026-10-13T12:30:00Z',
        ])->assertSessionHasNoErrors();

        $stored = DB::table('website_products')->where('id', $this->selection->id)->selectRaw(
            "to_char(promotion_starts_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') as starts, to_char(promotion_ends_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') as ends",
        )->first();

        expect($stored->starts)->toBe('2026-10-13 04:00')
            ->and($stored->ends)->toBe('2026-10-13 12:30')
            ->and($this->selection->refresh()->promotion_starts_at->getTimestamp())
            ->toBe(CarbonImmutable::parse('2026-10-13T04:00:00Z')->getTimestamp());
    });

    it('refuses a window it cannot honour', function (array $fields, string $field) {
        promotionWindowSave($fields)->assertSessionHasErrors($field);

        expect($this->selection->refresh()->promotion_starts_at)->toBeNull()
            ->and($this->selection->promotion_ends_at)->toBeNull();
    })->with([
        'an end before its start' => [[
            'promotional_price' => '2200.00',
            'promotion_starts_at' => '2026-10-13T10:00:00Z',
            'promotion_ends_at' => '2026-10-13T09:00:00Z',
        ], 'promotion_ends_at'],
        'an end equal to its start' => [[
            'promotional_price' => '2200.00',
            'promotion_starts_at' => '2026-10-13T10:00:00Z',
            'promotion_ends_at' => '2026-10-13T10:00:00Z',
        ], 'promotion_ends_at'],
        'a window with no promotion' => [['promotion_ends_at' => '2026-10-13T10:00:00Z'], 'promotion_starts_at'],
        'a date that is not one' => [['promotional_price' => '2200.00', 'promotion_starts_at' => 'tomorrow-ish'], 'promotion_starts_at'],
    ]);

    it('locks the window wherever the administrator locks the promotion', function () {
        $this->selection->forceFill(['promotional_price' => Money::fromDecimal('2200.00', Currency::BDT)])->save();

        WebsiteProductPriceRule::create([
            'product_id' => $this->selection->product_id,
            'allows_user_pricing' => true,
            'currency_code' => 'BDT',
            'locked_fields' => ['promotional_price'],
            'effective_from' => now()->subDay(),
        ]);

        promotionWindowSave(['promotion_ends_at' => '2026-10-13T10:00:00Z'])->assertSessionHasErrors('promotional_price');

        expect($this->selection->refresh()->promotion_ends_at)->toBeNull();
    });

    it('takes the window away with the promotion', function () {
        promotionWindowSave([
            'promotional_price' => '2200.00',
            'promotion_ends_at' => '2026-10-13T10:00:00Z',
        ])->assertSessionHasNoErrors();

        promotionWindowSave(['promotional_price' => null])->assertSessionHasNoErrors();

        expect($this->selection->refresh()->promotional_price)->toBeNull()
            ->and($this->selection->promotion_ends_at)->toBeNull();
    });

    it('is refused to a member of the same account who may not manage the website', function () {
        $member = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        promotionWindowSave([
            'promotional_price' => '2200.00',
            'promotion_ends_at' => '2026-10-13T10:00:00Z',
        ], actor: $member)->assertForbidden();

        expect($this->selection->refresh()->promotional_price)->toBeNull()
            ->and($this->selection->promotion_ends_at)->toBeNull();
    });

    it('never reaches another account\'s website', function () {
        $theirs = Website::factory()->forAccount(websiteTestAccount())->active()->create();

        promotionWindowSave([
            'promotional_price' => '2200.00',
            'promotion_ends_at' => '2026-10-13T10:00:00Z',
        ], $theirs)->assertNotFound();

        $outsider = websiteTestAccount()->owner;

        promotionWindowSave([
            'promotional_price' => '2200.00',
            'promotion_ends_at' => '2026-10-13T10:00:00Z',
        ], actor: $outsider)->assertNotFound();

        expect($this->selection->refresh()->promotional_price)->toBeNull();
    });
});

describe('what a window charges', function () {
    beforeEach(function () {
        [$this->credential, $this->secret] = storefrontCredential($this->website);

        $this->selection->forceFill([
            'promotional_price' => Money::fromDecimal('2200.00', Currency::BDT),
            'promotion_starts_at' => CarbonImmutable::parse('2026-10-12 13:00:00'),
            'promotion_ends_at' => CarbonImmutable::parse('2026-10-12 15:00:00'),
        ])->save();
    });

    it('charges and lists the same price before, during and after the window', function (string $at, string $price, ?string $compareAt) {
        CarbonImmutable::setTestNow($at);

        $quote = app(PriceWebsiteOrder::class)->quote($this->website->refresh(), [['sku' => $this->selection->product->sku, 'quantity' => 1]]);

        expect($quote->lines[0]->unitPrice->amount)->toBe($price);

        storefrontCall($this->credential, $this->secret, 'products/'.$this->selection->product->public_id)
            ->assertOk()
            ->assertJsonPath('variants.0.price.amount', $price)
            ->assertJsonPath('variants.0.compare_at_price.amount', $compareAt);

        storefrontCall($this->credential, $this->secret, 'products', ['price_max' => $price, 'price_min' => $price])
            ->assertOk()
            ->assertJsonCount(1, 'data');
    })->with([
        'before it opens' => ['2026-10-12 12:59:59', '2600.00', null],
        'the moment it opens' => ['2026-10-12 13:00:00', '2200.00', '2600.00'],
        'while it runs' => ['2026-10-12 14:00:00', '2200.00', '2600.00'],
        'the moment it closes' => ['2026-10-12 15:00:00', '2600.00', null],
    ]);

    it('tells the storefront when the deal ends', function () {
        CarbonImmutable::setTestNow('2026-10-12 14:00:00');

        storefrontCall($this->credential, $this->secret, 'products/'.$this->selection->product->public_id)
            ->assertOk()
            ->assertJsonPath('promotion.ends_at', CarbonImmutable::parse('2026-10-12 15:00:00')->toIso8601String());
    });
});

describe('announcing the edges', function () {
    it('announces each opening and closing once, however often the sweep runs', function () {
        promotionWindowSave([
            'promotional_price' => '2200.00',
            'promotion_starts_at' => '2026-10-12T13:00:00+06:00',
            'promotion_ends_at' => '2026-10-12T14:00:00+06:00',
        ])->assertSessionHasNoErrors();

        $afterSave = promotionWindowAnnouncements();
        $sweep = app(AnnouncePromotionWindows::class);

        expect($sweep->handle())->toBe(0)->and($sweep->handle())->toBe(0);

        CarbonImmutable::setTestNow('2026-10-12 13:00:00');

        expect($sweep->handle())->toBe(1)
            ->and($sweep->handle())->toBe(0)
            ->and(promotionWindowAnnouncements())->toBe($afterSave + 1);

        $announced = WebhookDelivery::query()->latest('id')->firstOrFail();
        expect($announced->event_type)->toBe(WebhookEvent::ProductPriceChanged)
            ->and($announced->payload['data']['price']['amount'])->toBe('2200.00');

        CarbonImmutable::setTestNow('2026-10-12 14:00:00');

        expect($sweep->handle())->toBe(1)
            ->and($sweep->handle())->toBe(0)
            ->and(promotionWindowAnnouncements())->toBe($afterSave + 2)
            ->and($this->selection->refresh()->promotion_live)->toBeFalse();
    });

    it('never announces a promotion with no window, nor an edge the owner already saved', function () {
        promotionWindowSave(['promotional_price' => '2200.00'])->assertSessionHasNoErrors();

        expect($this->selection->refresh()->promotion_live)->toBeTrue()
            ->and(app(AnnouncePromotionWindows::class)->handle())->toBe(0);
    });

    it('records an unpublished selection\'s edge without telling the storefront', function () {
        $this->selection->forceFill([
            'status' => WebsiteProductStatus::Unpublished,
            'promotional_price' => Money::fromDecimal('2200.00', Currency::BDT),
            'promotion_starts_at' => CarbonImmutable::parse('2026-10-12 11:00:00'),
        ])->save();

        $before = promotionWindowAnnouncements();

        expect(app(AnnouncePromotionWindows::class)->handle())->toBe(1)
            ->and($this->selection->refresh()->promotion_live)->toBeTrue()
            ->and(promotionWindowAnnouncements())->toBe($before);
    });
});
