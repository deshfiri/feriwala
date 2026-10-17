<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Enums\FeeType;
use App\Domain\Inventory\Enums\StockReservationStatus;
use App\Domain\Inventory\PartnerStock;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\CheckoutVerification;
use App\Domain\Website\CodTerms;
use App\Domain\Website\Enums\CredentialScope;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * What a storefront's owner and the platform decide about checkout: whether a
 * cash-on-delivery customer confirms with an SMS code, and where the banner goes.
 *
 * The platform's "require it everywhere" always wins; otherwise each storefront
 * chooses for itself. Storefront customer orders only — account and Supplier
 * verification are not touched.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

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
    websiteTestFee(FeeType::WebsiteDelivery, 6000);

    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);
    $this->website = checkoutOptionsShop($this->account);
    [$this->credential, $this->secret] = storefrontCredential($this->website, [CredentialScope::CatalogRead, CredentialScope::OrdersWrite, CredentialScope::OrdersRead]);
    $this->selection = WebsiteProduct::query()->where('website_id', $this->website->id)->sole();
});

/**
 * A storefront of the account with one published product it owns 20 of.
 */
function checkoutOptionsShop($account): Website
{
    $website = Website::factory()->forAccount($account)->active()->create();

    websiteTestOwnsSelection(WebsiteProduct::create([
        'website_id' => $website->id,
        'business_account_id' => $account->id,
        'product_id' => websiteTestProduct()->id,
        'status' => WebsiteProductStatus::Published,
        'sync_status' => WebsiteSyncStatus::Pending,
        'currency_code' => 'BDT',
        'price' => Money::fromDecimal('2600.00', Currency::BDT),
        'published_at' => now(),
    ]), 20);

    return $website;
}

/**
 * @param  array{0: mixed, 1: string}|null  $credential
 */
function checkoutOptionsOrder(?string $key = null, ?array $credential = null, ?string $sku = null): TestResponse
{
    [$record, $secret] = $credential ?? [test()->credential, test()->secret];
    $money = fn (string $amount) => ['amount' => $amount, 'currency' => 'BDT'];

    return storefrontCall($record, $secret, 'orders', method: 'POST', body: (string) json_encode([
        'storefront_order_reference' => 'SF-OPT-'.($key !== null ? strtoupper(substr(md5($key), 0, 6)) : Str::upper(Str::random(6))),
        'customer' => ['name' => 'Ayesha Rahman', 'phone' => '01712-345678', 'is_guest' => true],
        'shipping_address' => ['line1' => 'House 12', 'city' => 'Dhaka', 'country' => 'BD'],
        'items' => [['sku' => $sku ?? test()->selection->product->sku, 'quantity' => 2, 'unit_price' => $money('2600.00')]],
        'totals' => [
            'subtotal' => $money('5200.00'),
            'discount' => $money('0.00'),
            'shipping' => $money('60.00'),
            'tax' => $money('0.00'),
            'grand_total' => $money('5260.00'),
        ],
        'payment' => ['method' => 'cod'],
    ]), overrides: ['headers' => ['Idempotency-Key' => $key ?? (string) Str::uuid(), 'Content-Type' => 'application/json']]);
}

function checkoutOptionsSite(?array $credential = null): TestResponse
{
    [$record, $secret] = $credential ?? [test()->credential, test()->secret];

    return storefrontCall($record, $secret, 'site');
}

describe('the SMS code for cash-on-delivery orders', function () {
    it('asks for it by default, exactly as every shop always has', function () {
        checkoutOptionsOrder()->assertCreated()->assertJsonPath('status', OrderStatus::CustomerVerificationPending->value);

        expect($this->sent)->toHaveCount(1)->and($this->sent->first()->event)->toBe('cod_confirmation');
    });

    it('lets the customer confirm only with the right code when it is on', function () {
        $placed = checkoutOptionsOrder()->assertCreated();
        $reference = $placed->json('id');

        storefrontCall($this->credential, $this->secret, 'orders/'.$reference.'/confirmation', method: 'POST', body: (string) json_encode(['code' => '000000']), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'confirmation_refused');

        expect(websiteTestOrders()->sole()->status)->toBe(OrderStatus::CustomerVerificationPending);

        preg_match_all('/(?<![\d-])(\d{6})(?![\d-])/', (string) $this->sent->last()->body, $codes);

        storefrontCall($this->credential, $this->secret, 'orders/'.$reference.'/confirmation', method: 'POST', body: (string) json_encode(['code' => end($codes[1])]), overrides: ['headers' => ['Idempotency-Key' => (string) Str::uuid(), 'Content-Type' => 'application/json']])
            ->assertOk()
            ->assertJsonPath('confirmation.state', 'confirmed');

        expect(websiteTestOrders()->sole()->status)->toBe(OrderStatus::Confirmed);
    });

    it('skips the step when the shop has turned it off: no text, no waiting, stock committed once', function () {
        $this->website->forceFill(['sms_verification_enabled' => false])->save();

        $response = checkoutOptionsOrder()->assertCreated();

        $order = websiteTestOrders()->sole();

        expect($response->json('status'))->toBe(OrderStatus::Confirmed->value)
            ->and($order->status)->toBe(OrderStatus::Confirmed)
            ->and($order->items()->sole()->stockReservation->status)->toBe(StockReservationStatus::Committed)
            ->and($this->sent)->toBeEmpty()
            ->and(DB::table('order_status_history')->where('order_id', $order->id)->count())->toBe(2);
    });

    it('answers a repeated request with the same order and confirms it only once', function () {
        $this->website->forceFill(['sms_verification_enabled' => false])->save();
        $key = (string) Str::uuid();

        $first = checkoutOptionsOrder($key)->assertCreated();
        $again = checkoutOptionsOrder($key)->assertCreated();

        expect($again->json('id'))->toBe($first->json('id'))
            ->and(websiteTestOrders()->count())->toBe(1);

        $stock = app(PartnerStock::class)->itemFor($this->account, $this->selection->product_id, null);

        expect($stock->processing)->toBe(2)->and($stock->reserved)->toBe(0)->and($stock->available)->toBe(18);
    });

    it('is the shop\'s own choice, one storefront at a time', function () {
        $other = checkoutOptionsShop($this->account);
        $otherCredential = storefrontCredential($other, [CredentialScope::CatalogRead, CredentialScope::OrdersWrite, CredentialScope::OrdersRead]);

        $this->actingAs($this->account->owner)
            ->put(route('websites.settings.sms-verification.update', $this->website->public_id), ['enabled' => false])
            ->assertRedirect();

        expect($this->website->refresh()->sms_verification_enabled)->toBeFalse()
            ->and($other->refresh()->sms_verification_enabled)->toBeTrue();

        checkoutOptionsSite()->assertOk()->assertJsonPath('data.checkout.sms_verification_required', false);
        checkoutOptionsSite($otherCredential)->assertOk()->assertJsonPath('data.checkout.sms_verification_required', true);
    });

    it('is an owner\'s to change on their own storefront and nobody else\'s', function () {
        $stranger = websiteTestAccount();

        $this->actingAs($stranger->owner)
            ->put(route('websites.settings.sms-verification.update', $this->website->public_id), ['enabled' => false])
            ->assertNotFound();

        expect($this->website->refresh()->sms_verification_enabled)->toBeTrue();
    });
});

describe('the platform policy', function () {
    beforeEach(function () {
        $this->staff = testPlatformStaff(PlatformRole::SuperAdmin);
    });

    it('is set by platform staff, audited, and overrides every shop\'s own choice', function () {
        $this->website->forceFill(['sms_verification_enabled' => false])->save();

        $this->actingAs($this->staff)
            ->put(route('admin.sms.storefront-verification.update'), ['required' => true])
            ->assertRedirect();

        expect(app(CheckoutVerification::class)->requiredByPlatform())->toBeTrue()
            ->and(DB::table('audit_logs')->where('action', 'website.sms_verification_policy_changed')->count())->toBe(1);

        checkoutOptionsSite()->assertOk()->assertJsonPath('data.checkout.sms_verification_required', true);

        checkoutOptionsOrder()->assertCreated()->assertJsonPath('status', OrderStatus::CustomerVerificationPending->value);
        expect($this->sent)->toHaveCount(1);
    });

    it('cannot be switched off by a partner, and says why', function () {
        app(SettingsRepository::class)->define(CheckoutVerification::POLICY, 'orders', SettingType::Boolean, false);
        app(SettingsRepository::class)->set(CheckoutVerification::POLICY, true);

        $this->actingAs($this->account->owner)
            ->from(route('websites.settings.edit', $this->website->public_id))
            ->put(route('websites.settings.sms-verification.update', $this->website->public_id), ['enabled' => false])
            ->assertSessionHasErrors(['sms_verification_enabled' => __('website.refused.sms_verification_required_by_platform')]);

        expect($this->website->refresh()->sms_verification_enabled)->toBeTrue();

        $this->actingAs($this->account->owner)
            ->get(route('websites.settings.edit', $this->website->public_id))
            ->assertInertia(fn ($page) => $page
                ->where('sms_verification.locked', true)
                ->where('sms_verification.effective', true));
    });

    it('leaves each partner free again once the policy is lifted', function () {
        $this->actingAs($this->staff)->put(route('admin.sms.storefront-verification.update'), ['required' => true]);
        $this->actingAs($this->staff)->put(route('admin.sms.storefront-verification.update'), ['required' => false]);

        $this->actingAs($this->account->owner)
            ->put(route('websites.settings.sms-verification.update', $this->website->public_id), ['enabled' => false])
            ->assertRedirect();

        expect($this->website->refresh()->sms_verification_enabled)->toBeFalse();
    });

    it('is not for a partner to set', function () {
        $this->actingAs($this->account->owner)
            ->put(route('admin.sms.storefront-verification.update'), ['required' => true])
            ->assertForbidden();

        expect(app(CheckoutVerification::class)->requiredByPlatform())->toBeFalse();
    });
});

describe('the banner link', function () {
    it('stores a page on the shop or an https address, and tells the storefront', function () {
        foreach (['/products', 'https://example.com/eid-sale'] as $link) {
            $this->actingAs($this->account->owner)
                ->put(route('websites.settings.update', $this->website->public_id), checkoutOptionsSettings(['banner_link_url' => $link]))
                ->assertSessionHasNoErrors();

            expect($this->website->refresh()->banner_link_url)->toBe($link);
            checkoutOptionsSite()->assertOk()->assertJsonPath('data.branding.banner_link_url', $link);
        }
    });

    it('clears the link when the field is emptied', function () {
        $this->website->forceFill(['banner_link_url' => '/products'])->save();

        $this->actingAs($this->account->owner)
            ->put(route('websites.settings.update', $this->website->public_id), checkoutOptionsSettings(['banner_link_url' => '']))
            ->assertSessionHasNoErrors();

        expect($this->website->refresh()->banner_link_url)->toBeNull();
        checkoutOptionsSite()->assertJsonPath('data.branding.banner_link_url', null);
    });

    it('refuses anything that is not a safe address', function (string $link) {
        $this->actingAs($this->account->owner)
            ->put(route('websites.settings.update', $this->website->public_id), checkoutOptionsSettings(['banner_link_url' => $link]))
            ->assertSessionHasErrors('banner_link_url');

        expect($this->website->refresh()->banner_link_url)->toBeNull();
    })->with([
        'script' => 'javascript:alert(1)',
        'data' => 'data:text/html,<script>1</script>',
        'protocol-relative' => '//evil.example.com',
        'plain http' => 'http://example.com',
        'credentials' => 'https://user:pass@example.com',
        'backslash' => '/\\evil.example.com',
        'spaces' => '/a b',
        'no scheme' => 'example.com/sale',
    ]);

    it('never hands the storefront an address it would not have accepted', function () {
        $this->website->forceFill(['banner_link_url' => 'javascript:alert(1)'])->save();

        checkoutOptionsSite()->assertOk()->assertJsonPath('data.branding.banner_link_url', null);
    });
});

/**
 * The whole settings form, as the page submits it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutOptionsSettings(array $overrides = []): array
{
    $website = test()->website->refresh();

    return [
        'name' => $website->name,
        'tagline' => $website->tagline,
        'about' => $website->about,
        'theme' => $website->theme->value,
        'primary_color' => $website->primary_color,
        'secondary_color' => $website->secondary_color,
        'contact_email' => $website->contact_email,
        'contact_phone' => $website->contact_phone,
        'contact_address' => $website->contact_address,
        ...$overrides,
    ];
}
