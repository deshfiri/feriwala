<?php

use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Actions\ManageWebsiteBranding;
use App\Domain\Website\Actions\ManageWebsiteDomainRouting;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteDomain;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Telling a storefront that how its website is presented changed (P2 D2).
 *
 * A hint to read `GET /site` again: the website and the moment, nothing more.
 */
beforeEach(function () {
    Http::fake();

    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create(['domain' => 'shop.test']);
    app(ManageWebhookEndpoint::class)->configure($this->website, $this->account->owner, 'https://shop.test/feriwala/webhooks');
});

/**
 * @return list<WebhookDelivery>
 */
function websiteUpdatedDeliveries(): array
{
    return WebhookDelivery::query()
        ->where('website_id', test()->website->id)
        ->where('event_type', WebhookEvent::WebsiteUpdated->value)
        ->orderBy('id')
        ->get()
        ->all();
}

it('announces a branding change with nothing but the website and the moment', function () {
    app(ManageWebsiteBranding::class)->update($this->website, $this->account->owner, [
        'tagline' => 'Fresh every Friday',
        'contact_phone' => '01712345678',
    ]);

    $deliveries = websiteUpdatedDeliveries();

    expect($deliveries)->toHaveCount(1)
        ->and(array_keys($deliveries[0]->payload['data']))->toBe(['id', 'updated_at'])
        ->and($deliveries[0]->payload['data']['id'])->toBe($this->website->public_id);
});

it('says nothing when a save changed nothing', function () {
    app(ManageWebsiteBranding::class)->update($this->website, $this->account->owner, ['tagline' => 'Same']);
    $before = count(websiteUpdatedDeliveries());

    app(ManageWebsiteBranding::class)->update($this->website->refresh(), $this->account->owner, ['tagline' => 'Same']);

    expect(websiteUpdatedDeliveries())->toHaveCount($before);
});

it('announces a new or removed logo', function () {
    Storage::fake('public');
    Storage::fake();

    $branding = app(ManageWebsiteBranding::class);
    $branding->putImage($this->website, $this->account->owner, 'logo', UploadedFile::fake()->image('logo.png', 200, 200));
    $branding->removeImage($this->website, $this->account->owner, 'logo');

    expect(websiteUpdatedDeliveries())->toHaveCount(2);
});

it('announces a domain starting or stopping serving the storefront', function () {
    $domain = WebsiteDomain::factory()->verified()->create(['website_id' => $this->website->id, 'domain' => 'shop.test']);
    $routing = app(ManageWebsiteDomainRouting::class);

    $routing->activate($domain, $this->account->owner);
    $routing->suspend($domain, $this->account->owner, 'Lapsed DNS');

    expect(websiteUpdatedDeliveries())->toHaveCount(2);
});

it('tells a shop that is not being served nothing', function () {
    $this->website->forceFill(['status' => WebsiteStatus::Suspended])->save();

    app(ManageWebsiteBranding::class)->update($this->website, $this->account->owner, ['tagline' => 'Back soon']);

    expect(websiteUpdatedDeliveries())->toBeEmpty();
});
