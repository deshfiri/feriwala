<?php

use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Exceptions\WebsiteRefused;
use App\Domain\Website\Models\Website;
use App\Integrations\Store\Contracts\HostResolver;

/**
 * A webhook address is refused for one of several reasons, and the operator is
 * told which, so a refusal says what to change (contract §3.3, §7.2). The rules
 * themselves are as strict as they ever were.
 */
beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
});

it('names the rule an address broke', function (string $url, string $reason) {
    $refusal = null;

    try {
        app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, $url);
    } catch (WebsiteRefused $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(WebsiteRefused::class)
        ->and($refusal->getMessage())->toBe(__('website.refused.webhook_address_'.$reason));
})->with([
    'plain http' => ['http://shop.example.com/hook', 'scheme'],
    'no scheme' => ['shop.example.com/hook', 'scheme'],
    'user name and password' => ['https://user:secret@shop.example.com/hook', 'credentials'],
    'another port' => ['https://shop.example.com:8443/hook', 'port'],
    'localhost' => ['https://localhost/hook', 'local'],
    'a .internal name' => ['https://billing.internal/hook', 'local'],
    'loopback address' => ['https://127.0.0.1/hook', 'local'],
    'private address' => ['https://10.0.0.5/hook', 'local'],
]);

it('says so when the host name points somewhere private', function () {
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['10.20.30.40'];
        }
    });

    expect(fn () => app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com/hook'))
        ->toThrow(WebsiteRefused::class, __('website.refused.webhook_address_private'));
});

it('accepts a host name that does not resolve yet and leaves the endpoint active', function () {
    app()->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return [];
        }
    });

    $result = app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://dascart.com/webhooks/feriwala');

    expect($result['endpoint']->url)->toBe('https://dascart.com/webhooks/feriwala')
        ->and($result['endpoint']->is_active)->toBeTrue()
        ->and($result['secret'])->not->toBeNull();
});

it('still accepts a public HTTPS address on the standard port', function () {
    $result = app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.example.com:443/feriwala/webhooks');

    expect($result['endpoint']->url)->toBe('https://shop.example.com:443/feriwala/webhooks');
});
