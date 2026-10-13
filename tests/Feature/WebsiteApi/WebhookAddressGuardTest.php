<?php

use App\Domain\Website\Actions\AttemptWebhookDelivery;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Actions\PublishWebsiteEvent;
use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\WebhookLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Integrations\Store\Contracts\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Webhooks never call inside the platform's network (P2 D3).
 *
 * The address is resolved when it is registered and again on every delivery
 * attempt, every address it gives must be public, the connection is pinned to
 * the address checked, and a redirect is never followed.
 */
beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    // Where `hooks.shop.example` points; a test repoints it.
    $this->dns = new ArrayObject(['addresses' => ['93.184.216.34']]);

    app()->instance(HostResolver::class, new class($this->dns) implements HostResolver
    {
        public function __construct(private readonly ArrayObject $dns) {}

        public function resolve(string $host): array
        {
            return filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns['addresses'];
        }
    });
});

function webhookGuardRegister(string $url): void
{
    app(ManageWebhookEndpoint::class)->configure(test()->website, test()->account->owner, $url);
}

/**
 * One delivery, queued but not yet attempted.
 */
function webhookGuardDelivery(): WebhookDelivery
{
    Queue::fake();

    $delivery = app(PublishWebsiteEvent::class)->handle(test()->website, WebhookEvent::CategoryUpdated, ['categories' => []]);

    expect($delivery)->not->toBeNull();

    return $delivery;
}

describe('registering an address', function () {
    it('refuses a name that resolves inside the network', function (array $addresses) {
        $this->dns['addresses'] = $addresses;

        expect(fn () => webhookGuardRegister('https://hooks.shop.example/feriwala'))->toThrow(WebsiteRefused::class);
    })->with([
        'loopback' => [['127.0.0.1']],
        'a private range' => [['10.0.0.5']],
        'cloud metadata' => [['169.254.169.254']],
        'carrier-grade NAT' => [['100.64.0.9']],
        'IPv6 loopback' => [['::1']],
        'IPv4 inside IPv6' => [['::ffff:192.168.1.1']],
        'one public, one private' => [['93.184.216.34', '10.0.0.5']],
        'nothing at all' => [[]],
    ]);

    it('refuses an address that is not plain HTTPS on the standard port', function (string $url) {
        expect(fn () => webhookGuardRegister($url))->toThrow(WebsiteRefused::class);
    })->with([
        'http' => 'http://hooks.shop.example/feriwala',
        'another port' => 'https://hooks.shop.example:8443/feriwala',
        'credentials' => 'https://user:pw@hooks.shop.example/feriwala',
        'a private literal' => 'https://192.168.0.10/feriwala',
        'localhost' => 'https://localhost/feriwala',
    ]);

    it('accepts a name that resolves only to public addresses', function () {
        webhookGuardRegister('https://hooks.shop.example/feriwala');

        expect(WebsiteWebhookEndpoint::query()->where('website_id', $this->website->id)->value('url'))
            ->toBe('https://hooks.shop.example/feriwala');
    });
});

describe('delivering', function () {
    beforeEach(function () {
        $this->answer = new ArrayObject(['status' => 200, 'headers' => []]);

        Http::fake(fn () => Http::response('', $this->answer['status'], $this->answer['headers']));
        webhookGuardRegister('https://hooks.shop.example/feriwala');
    });

    it('refuses to send once the name has been pointed inside the network, on every retry', function () {
        $delivery = webhookGuardDelivery();
        $this->dns['addresses'] = ['169.254.169.254'];

        app(AttemptWebhookDelivery::class)->handle($delivery->id);

        $delivery->refresh();

        expect($delivery->state)->toBe(WebhookDeliveryState::Retrying)
            ->and($delivery->last_error)->toBe(AttemptWebhookDelivery::ADDRESS_REFUSED)
            ->and(WebhookLog::query()->where('webhook_delivery_id', $delivery->id)->value('error'))->toBe(AttemptWebhookDelivery::ADDRESS_REFUSED);

        $this->travel(1)->hour();
        app(AttemptWebhookDelivery::class)->handle($delivery->id);

        expect($delivery->refresh()->attempt)->toBe(2)
            ->and($delivery->last_error)->toBe(AttemptWebhookDelivery::ADDRESS_REFUSED);

        Http::assertNothingSent();

        // Pointed back at a public server, the next retry goes through.
        $this->dns['addresses'] = ['93.184.216.34'];
        $this->travel(1)->hour();
        app(AttemptWebhookDelivery::class)->handle($delivery->id);

        expect($delivery->refresh()->state)->toBe(WebhookDeliveryState::Delivered);
        Http::assertSentCount(1);
    });

    it('treats a redirect as an answer, never as somewhere to send signed data', function () {
        $this->answer['status'] = 302;
        $this->answer['headers'] = ['Location' => 'http://169.254.169.254/latest/meta-data'];

        $delivery = webhookGuardDelivery();

        app(AttemptWebhookDelivery::class)->handle($delivery->id);

        expect($delivery->refresh()->state)->toBe(WebhookDeliveryState::Retrying)
            ->and($delivery->response_status)->toBe(302);

        Http::assertSentCount(1);
    });
});
