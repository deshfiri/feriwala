<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Billing\Actions\ConfigureGateway;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Jobs\AnnouncePaymentMethodsChanged;
use App\Domain\Website\Jobs\DeliverWebhook;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Integrations\Payment\PaymentGatewayManager;
use App\Support\Money\Currency;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Telling storefronts when online payment comes or goes (P2 D2).
 *
 * `GET /site` offers online payment in a currency while any gateway can take
 * it. A gateway change that flips that tells every served website in the
 * currency with a webhook endpoint — queued, one pending event per website —
 * and a change that flips nothing tells nobody.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::PaymentManager);

    // SSLCommerz, credentialled in sandbox: the one gateway taking taka.
    $settings = app(SettingsRepository::class);
    $settings->define('payment.sslcommerz.mode', 'payment', SettingType::String, 'sandbox');
    $settings->define('payment.sslcommerz.sandbox.store_id', 'payment', SettingType::String, 'store', isEncrypted: true);
    $settings->define('payment.sslcommerz.sandbox.store_password', 'payment', SettingType::String, 'pass', isEncrypted: true);

    // Deliveries stay pending, so what was announced, and how often, can be read.
    Queue::fake([DeliverWebhook::class]);

    $this->listening = paymentAnnouncementWebsite(['domain' => 'listening.test']);
    $this->silent = Website::factory()->forAccount(websiteTestAccount())->active()->create();
    $this->suspended = paymentAnnouncementWebsite(['domain' => 'suspended.test']);
    $this->suspended->forceFill(['status' => WebsiteStatus::Suspended])->save();
    // A website selling in another currency. Set below the model: the factory's
    // own fees are in taka, and only the selling currency matters here.
    $this->dollars = paymentAnnouncementWebsite(['domain' => 'dollars.test']);
    DB::table('websites')->where('id', $this->dollars->id)->update(['currency_code' => 'USD']);

    expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->toBe(['sslcommerz']);
});

/**
 * A served website with a webhook endpoint.
 *
 * @param  array<string, mixed>  $attributes
 */
function paymentAnnouncementWebsite(array $attributes): Website
{
    $account = websiteTestAccount();
    $website = Website::factory()->forAccount($account)->active()->create($attributes);

    app(ManageWebhookEndpoint::class)->configure($website, $account->owner, 'https://'.$attributes['domain'].'/feriwala/webhooks');

    return $website;
}

/**
 * `website.updated` deliveries per website id.
 *
 * @return array<int, int>
 */
function paymentAnnouncementCounts(): array
{
    return WebhookDelivery::query()
        ->where('event_type', WebhookEvent::WebsiteUpdated->value)
        ->groupBy('website_id')
        ->selectRaw('website_id, count(*) as deliveries')
        ->pluck('deliveries', 'website_id')
        ->map(fn ($count) => (int) $count)
        ->all();
}

it('tells the served, listening websites in taka when online payment is switched off, and nobody else', function () {
    $this->actingAs($this->manager)
        ->put(route('admin.gateways.toggle'), ['gateway' => 'sslcommerz', 'enabled' => false])
        ->assertRedirect();

    expect(paymentAnnouncementCounts())->toBe([$this->listening->id => 1]);

    $delivery = WebhookDelivery::query()->where('website_id', $this->listening->id)->sole();

    expect($delivery->state)->toBe(WebhookDeliveryState::Pending)
        ->and(array_keys($delivery->payload['data']))->toBe(['id', 'updated_at']);
});

it('tells them again when it comes back, as one pending event each', function () {
    $configure = app(ConfigureGateway::class);

    $configure->setEnabled($this->manager, 'sslcommerz', false);
    $configure->setEnabled($this->manager, 'sslcommerz', true);

    expect(paymentAnnouncementCounts())->toBe([$this->listening->id => 1])
        ->and(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->toBe(['sslcommerz']);
});

it('announces a configuration change that takes online payment away', function () {
    // Live mode with only sandbox credentials: the gateway switches itself off.
    app(ConfigureGateway::class)->handle($this->manager, 'sslcommerz', 'live', []);

    expect(app(PaymentGatewayManager::class)->availableFor(Currency::BDT))->toBe([])
        ->and(paymentAnnouncementCounts())->toBe([$this->listening->id => 1]);
});

it('tells nobody about a change that leaves every checkout as it was', function () {
    $configure = app(ConfigureGateway::class);

    // A credential rotated on a working gateway.
    $configure->handle($this->manager, 'sslcommerz', 'sandbox', ['store_password' => 'rotated']);

    // Switching on what is already on.
    $configure->setEnabled($this->manager, 'sslcommerz', true);

    expect(paymentAnnouncementCounts())->toBe([]);
});

it('fans out on the queue, never in the admin\'s request', function () {
    Queue::fake([DeliverWebhook::class, AnnouncePaymentMethodsChanged::class]);

    app(ConfigureGateway::class)->setEnabled($this->manager, 'sslcommerz', false);

    Queue::assertPushed(AnnouncePaymentMethodsChanged::class, fn (AnnouncePaymentMethodsChanged $job) => $job->currencies === ['BDT']);
    expect(paymentAnnouncementCounts())->toBe([]);

    app(ConfigureGateway::class)->setEnabled($this->manager, 'sslcommerz', false);

    Queue::assertPushed(AnnouncePaymentMethodsChanged::class, 1);
});
