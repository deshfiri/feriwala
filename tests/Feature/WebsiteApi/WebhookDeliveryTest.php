<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductMedia;
use App\Domain\Package\Enums\PackageFeature;
use App\Domain\Website\Actions\DispatchDueWebhookRetries;
use App\Domain\Website\Actions\ManageWebhookEndpoint;
use App\Domain\Website\Actions\PublishWebsiteEvent;
use App\Domain\Website\Actions\SyncWebsiteCatalogue;
use App\Domain\Website\Actions\SyncWebsiteInventory;
use App\Domain\Website\Actions\SyncWebsiteProduct;
use App\Domain\Website\Api\WebhookSignature;
use App\Domain\Website\Enums\WebhookDeliveryState;
use App\Domain\Website\Enums\WebhookEvent;
use App\Domain\Website\Enums\WebsiteConnectionHealth;
use App\Domain\Website\Enums\WebsiteProductStatus;
use App\Domain\Website\Enums\WebsiteSyncStatus;
use App\Domain\Website\Models\SyncQueueFailure;
use App\Domain\Website\Models\WebhookDelivery;
use App\Domain\Website\Models\WebhookLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteProduct;
use App\Domain\Website\Models\WebsiteWebhookEndpoint;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Telling a storefront that something changed (contract §7, §17.2, §17.3,
 * P5-20, P5-22, P5-25, P5-26, P5-27, P5-28).
 *
 * Signed, sent only to the website the change belongs to, retried on the
 * contract's schedule, dead-lettered for a person when the retries run out, and
 * written down every time — never with the signature.
 */
beforeEach(function () {
    $this->account = websiteTestAccount(extra: [
        PackageFeature::DropshippingEnabled->value => '1',
        PackageFeature::ProductPublishLimit->value => null,
    ]);

    $this->website = Website::factory()->forAccount($this->account)->active()->create();

    // What the fake storefront answers; a test changes it to make it fail.
    $this->answer = 200;

    Http::fake(fn () => Http::response('ok', $this->answer));
});

/**
 * A published selection on the website, at 2,600 taka.
 */
function webhookTestSelection(Website $website): WebsiteProduct
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

/**
 * The website's endpoint, and the secret it was issued.
 *
 * @return array{0: WebsiteWebhookEndpoint, 1: string}
 */
function webhookTestEndpoint(Website $website, string $url = 'https://shop.example.com/feriwala/webhooks'): array
{
    $result = app(ManageWebhookEndpoint::class)->configure($website, $website->businessAccount->owner, $url);

    return [$result['endpoint'], (string) $result['secret']];
}

/**
 * Spend every automatic retry on a delivery the storefront keeps refusing.
 */
function webhookTestExhaust(WebhookDelivery $delivery): WebhookDelivery
{
    $delivery->refresh()->forceFill([
        'state' => WebhookDeliveryState::Retrying,
        'attempt' => 8,
        'next_retry_at' => now()->subSecond(),
    ])->save();

    app(DispatchDueWebhookRetries::class)->handle();

    return $delivery->refresh();
}

describe('signature', function () {
    it('verifies a fresh signature and refuses a stale, tampered or malformed one', function () {
        $now = 1_800_000_000;
        $body = '{"event":"product.updated"}';
        $signature = WebhookSignature::sign('current-secret', (string) $now, $body);

        expect($signature)->toStartWith('sha256=')
            ->and(WebhookSignature::verify(['current-secret'], (string) $now, $body, $signature, $now + 299))->toBeTrue()
            ->and(WebhookSignature::verify(['current-secret'], (string) $now, $body, $signature, $now + 301))->toBeFalse()
            ->and(WebhookSignature::verify(['current-secret'], (string) $now, $body.' ', $signature, $now))->toBeFalse()
            ->and(WebhookSignature::verify(['another-secret'], (string) $now, $body, $signature, $now))->toBeFalse()
            ->and(WebhookSignature::verify(['current-secret'], 'yesterday', $body, $signature, $now))->toBeFalse();
    });

    it('accepts the previous secret while a rotation is in progress', function () {
        $now = 1_800_000_000;
        $signature = WebhookSignature::sign('old-secret', (string) $now, '{}');

        expect(WebhookSignature::verify(['new-secret', 'old-secret'], (string) $now, '{}', $signature, $now))->toBeTrue();
    });
});

describe('endpoint', function () {
    it('saves an HTTPS address and shows its signing secret once', function () {
        $this->actingAs($this->account->owner)
            ->put(route('websites.webhook.store', $this->website->public_id), [
                'url' => 'https://shop.example.com/feriwala/webhooks',
            ])
            ->assertRedirect(route('websites.integration.show', $this->website->public_id));

        $endpoint = WebsiteWebhookEndpoint::query()->where('website_id', $this->website->id)->firstOrFail();

        expect($endpoint->is_active)->toBeTrue()
            ->and(strlen($endpoint->secret))->toBe(64)
            ->and(DB::table('website_webhook_endpoints')->where('id', $endpoint->id)->value('secret'))->not->toBe($endpoint->secret);

        $landing = $this->actingAs($this->account->owner)
            ->get(route('websites.integration.show', $this->website->public_id));

        expect($landing->viewData('page')['flash']['webhook_secret'] ?? null)->toBe($endpoint->secret)
            ->and($landing->viewData('page')['props']['webhook']['secret_hint'])->toBe(substr($endpoint->secret, -4))
            ->and(json_encode($landing->viewData('page')['props']))->not->toContain($endpoint->secret);

        $reload = $this->actingAs($this->account->owner)
            ->get(route('websites.integration.show', $this->website->public_id));

        expect(json_encode($reload->viewData('page')))->not->toContain($endpoint->secret)
            ->and(AuditLog::query()->get()->contains(fn (AuditLog $log) => str_contains((string) json_encode($log->toArray()), $endpoint->secret)))
            ->toBeFalse();
    });

    it('refuses an address the ERP must not post to', function (string $url) {
        $this->actingAs($this->account->owner)
            ->from(route('websites.integration.show', $this->website->public_id))
            ->put(route('websites.webhook.store', $this->website->public_id), ['url' => $url])
            ->assertSessionHasErrors('url');

        expect(WebsiteWebhookEndpoint::query()->count())->toBe(0);
    })->with([
        'plain http' => 'http://shop.example.com/hooks',
        'localhost' => 'https://localhost/hooks',
        'loopback' => 'https://127.0.0.1/hooks',
        'private range' => 'https://10.0.0.8/hooks',
        'internal name' => 'https://queue.internal/hooks',
        'ipv6 loopback' => 'https://[::1]/hooks',
    ]);

    it('rotates the secret, keeping the previous one for its window', function () {
        [$endpoint, $secret] = webhookTestEndpoint($this->website);

        $this->actingAs($this->account->owner)
            ->post(route('websites.webhook.rotate', $this->website->public_id))
            ->assertRedirect();

        $endpoint->refresh();

        expect($endpoint->secret)->not->toBe($secret)
            ->and($endpoint->previous_secret)->toBe($secret)
            ->and($endpoint->previous_secret_expires_at)->not->toBeNull();
    });

    it('is managed only by the owner of the website', function () {
        $theirs = Website::factory()->forAccount(websiteTestAccount())->active()->create();

        $this->actingAs($this->account->owner)
            ->put(route('websites.webhook.store', $theirs->public_id), ['url' => 'https://shop.example.com/hooks'])
            ->assertNotFound();

        $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        $this->actingAs($staff)
            ->put(route('websites.webhook.store', $this->website->public_id), ['url' => 'https://shop.example.com/hooks'])
            ->assertForbidden();

        expect(WebsiteWebhookEndpoint::query()->count())->toBe(0);
    });
});

describe('delivery', function () {
    it('sends a signed hint to this website\'s storefront and no other', function () {
        [, $secret] = webhookTestEndpoint($this->website, 'https://ours.example.com/hooks');

        $other = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        webhookTestEndpoint($other, 'https://theirs.example.com/hooks');
        webhookTestSelection($other);

        $selection = webhookTestSelection($this->website);

        $delivery = app(SyncWebsiteProduct::class)->handle($selection, WebhookEvent::ProductUpdated);

        Http::assertSentCount(1);

        /** @var HttpRequest $request */
        $request = Http::recorded()->first()[0];
        $payload = json_decode($request->body(), true);

        expect($request->url())->toBe('https://ours.example.com/hooks')
            ->and($request->header(WebhookSignature::HEADER_EVENT)[0])->toBe('product.updated')
            ->and($request->header(WebhookSignature::HEADER_DELIVERY)[0])->toBe($delivery?->event_id)
            ->and(WebhookSignature::verify(
                [$secret],
                $request->header(WebhookSignature::HEADER_TIMESTAMP)[0],
                $request->body(),
                $request->header(WebhookSignature::HEADER_SIGNATURE)[0],
            ))->toBeTrue()
            ->and($payload['website_id'])->toBe($this->website->public_id)
            ->and($payload['data']['id'])->toBe($selection->product->public_id)
            ->and(array_keys($payload['data']))->toEqualCanonicalizing(['id', 'sku', 'slug', 'status', 'price', 'updated_at'])
            // A hint at the website's own price: never the wholesale figure.
            ->and($request->body())->not->toContain('wholesale')
            ->and($request->body())->not->toContain('150000');

        $delivery->refresh();
        $website = $this->website->refresh();

        expect($delivery->state)->toBe(WebhookDeliveryState::Delivered)
            ->and($delivery->attempt)->toBe(1)
            ->and($selection->refresh()->sync_status)->toBe(WebsiteSyncStatus::Synced)
            ->and($website->connection_health)->toBe(WebsiteConnectionHealth::Healthy)
            ->and($website->webhook_connected_at)->not->toBeNull()
            ->and(WebhookDelivery::query()->where('website_id', $other->id)->exists())->toBeFalse();
    });

    it('logs every attempt without the signature, and the log cannot be edited', function () {
        webhookTestEndpoint($this->website);

        app(SyncWebsiteProduct::class)->handle(webhookTestSelection($this->website), WebhookEvent::ProductUpdated);

        $sent = Http::recorded()->first()[0];
        $log = WebhookLog::query()->firstOrFail();

        expect($log->response_status)->toBe(200)
            ->and($log->request_headers)->not->toHaveKey(WebhookSignature::HEADER_SIGNATURE)
            ->and(json_encode($log->toArray()))->not->toContain($sent->header(WebhookSignature::HEADER_SIGNATURE)[0]);

        expect(fn () => DB::table('webhook_logs')->where('id', $log->id)->update(['response_status' => 500]))
            ->toThrow(QueryException::class);
    });

    it('schedules a retry when the storefront refuses, and waits for it to come due', function () {
        webhookTestEndpoint($this->website);
        $this->freezeSecond();
        $this->answer = 500;

        $delivery = app(SyncWebsiteProduct::class)->handle(webhookTestSelection($this->website), WebhookEvent::ProductUpdated)->refresh();

        expect($delivery->state)->toBe(WebhookDeliveryState::Retrying)
            ->and($delivery->response_status)->toBe(500)
            ->and($delivery->next_retry_at->equalTo(now()->addSeconds(10)))->toBeTrue()
            ->and($this->website->refresh()->connection_health)->toBe(WebsiteConnectionHealth::Degraded);

        expect(app(DispatchDueWebhookRetries::class)->handle())->toBe(0);
        Http::assertSentCount(1);

        $this->travel(11)->seconds();
        $this->answer = 200;

        expect(app(DispatchDueWebhookRetries::class)->handle())->toBe(1)
            ->and($delivery->refresh()->state)->toBe(WebhookDeliveryState::Delivered)
            ->and($delivery->attempt)->toBe(2);
    });

    it('dead-letters a delivery once the retries run out', function () {
        webhookTestEndpoint($this->website);
        $this->answer = 500;

        $selection = webhookTestSelection($this->website);
        $delivery = webhookTestExhaust(app(SyncWebsiteProduct::class)->handle($selection, WebhookEvent::ProductUpdated));

        $failure = SyncQueueFailure::query()->where('webhook_delivery_id', $delivery->id)->firstOrFail();

        expect($delivery->state)->toBe(WebhookDeliveryState::Failed)
            ->and($delivery->attempt)->toBe(9)
            ->and($delivery->next_retry_at)->toBeNull()
            ->and($failure->resolved_at)->toBeNull()
            ->and($failure->attempts)->toBe(9)
            ->and($selection->refresh()->sync_status)->toBe(WebsiteSyncStatus::Failed)
            ->and($this->website->refresh()->connection_health)->toBe(WebsiteConnectionHealth::Failing);

        // Failed is finished: the scheduler leaves it for a person.
        expect(app(DispatchDueWebhookRetries::class)->handle())->toBe(0);
    });

    it('queues nothing without an active endpoint or for a website not being served', function () {
        $publish = app(PublishWebsiteEvent::class);

        expect($publish->handle($this->website, WebhookEvent::ProductUpdated, []))->toBeNull();

        [$endpoint] = webhookTestEndpoint($this->website);
        app(ManageWebhookEndpoint::class)->disable($endpoint, $this->account->owner);

        expect($publish->handle($this->website, WebhookEvent::ProductUpdated, []))->toBeNull();

        $suspended = Website::factory()->forAccount($this->account)->suspended()->create();
        webhookTestEndpoint($suspended);

        expect($publish->handle($suspended, WebhookEvent::ProductUpdated, []))->toBeNull()
            // …except being told it was suspended.
            ->and($publish->handle($suspended, WebhookEvent::WebsiteSuspended, [])?->refresh()->state)
            ->toBe(WebhookDeliveryState::Delivered);

        Http::assertSentCount(1);
    });
});

describe('manual retry', function () {
    it('sends a failed delivery again with the same event identifier', function () {
        webhookTestEndpoint($this->website);
        $this->answer = 500;

        $delivery = webhookTestExhaust(
            app(SyncWebsiteProduct::class)->handle(webhookTestSelection($this->website), WebhookEvent::ProductUpdated),
        );

        $this->answer = 200;

        $this->actingAs($this->account->owner)
            ->post(route('websites.deliveries.retry', [$this->website->public_id, $delivery->event_id]))
            ->assertRedirect(route('websites.integration.show', $this->website->public_id))
            ->assertSessionHasNoErrors();

        $failure = SyncQueueFailure::query()->where('webhook_delivery_id', $delivery->id)->firstOrFail();

        expect($delivery->refresh()->state)->toBe(WebhookDeliveryState::Delivered)
            ->and($delivery->retried_by)->toBe($this->account->owner->id)
            ->and(Http::recorded()->last()[0]->header(WebhookSignature::HEADER_DELIVERY)[0])->toBe($delivery->event_id)
            ->and($failure->retry_count)->toBe(1)
            ->and($failure->resolved_at)->not->toBeNull()
            ->and(WebhookDelivery::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'website.webhook_retried')->exists())->toBeTrue();
    });

    it('refuses a delivery that is not waiting for a person', function () {
        webhookTestEndpoint($this->website);

        $delivery = app(SyncWebsiteProduct::class)->handle(webhookTestSelection($this->website), WebhookEvent::ProductUpdated);

        $this->actingAs($this->account->owner)
            ->from(route('websites.integration.show', $this->website->public_id))
            ->post(route('websites.deliveries.retry', [$this->website->public_id, $delivery?->event_id]))
            ->assertSessionHasErrors('delivery');

        Http::assertSentCount(1);
    });

    it('is refused for another account\'s delivery and for a staff member', function () {
        $theirs = Website::factory()->forAccount(websiteTestAccount())->active()->create();
        webhookTestEndpoint($theirs);
        $this->answer = 500;

        $delivery = webhookTestExhaust(
            app(SyncWebsiteProduct::class)->handle(webhookTestSelection($theirs), WebhookEvent::ProductUpdated),
        );

        $this->actingAs($this->account->owner)
            ->post(route('websites.deliveries.retry', [$theirs->public_id, $delivery->event_id]))
            ->assertNotFound();

        $this->actingAs($this->account->owner)
            ->post(route('websites.deliveries.retry', [$this->website->public_id, $delivery->event_id]))
            ->assertNotFound();

        $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

        $this->actingAs($staff)
            ->get(route('websites.integration.show', $this->website->public_id))
            ->assertForbidden();

        expect($delivery->refresh()->state)->toBe(WebhookDeliveryState::Failed);
    });
});

describe('synchronisation', function () {
    it('tells the storefront about stock only when it changed', function () {
        webhookTestEndpoint($this->website);
        $selection = webhookTestSelection($this->website);
        $updatedAt = $selection->refresh()->updated_at->toIso8601String();

        // Nothing in stock: updated, and out of stock the first time.
        expect(app(SyncWebsiteInventory::class)->handle())->toBe(1)
            ->and(WebhookDelivery::query()->pluck('event_type')->map->value->sort()->values()->all())
            ->toBe(['inventory.out_of_stock', 'inventory.updated']);

        // Unchanged: nothing sent, and the product's own timestamp untouched.
        expect(app(SyncWebsiteInventory::class)->handle())->toBe(0)
            ->and(WebhookDelivery::query()->count())->toBe(2)
            ->and($selection->refresh()->updated_at->toIso8601String())->toBe($updatedAt)
            ->and($selection->synced_availability[0])->toMatchArray(['sku' => $selection->product->sku, 'in_stock' => false])
            ->and(array_keys($selection->synced_availability[0]))->toEqualCanonicalizing(['sku', 'in_stock', 'quantity']);
    });

    it('does not announce a product again while its delivery is still being retried', function () {
        webhookTestEndpoint($this->website);
        $this->answer = 500;

        $selection = webhookTestSelection($this->website);
        $catalogue = app(SyncWebsiteCatalogue::class);

        expect($catalogue->sweep())->toBe(1)
            ->and($catalogue->sweep())->toBe(0)
            ->and(WebhookDelivery::query()->where('subject_id', $selection->id)->count())->toBe(1);
    });

    it('announces a product Feriwala changed after the storefront last synchronised', function () {
        webhookTestEndpoint($this->website);
        $selection = webhookTestSelection($this->website);

        WebsiteProduct::query()->whereKey($selection->id)->toBase()->update([
            'sync_status' => WebsiteSyncStatus::Synced->value,
            'last_synced_at' => now()->subHour(),
        ]);
        $selection->product->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        $catalogue = app(SyncWebsiteCatalogue::class);

        expect($catalogue->sweep())->toBe(0);

        $selection->product->forceFill(['updated_at' => now()])->saveQuietly();

        expect($catalogue->sweep())->toBe(1)
            ->and($selection->refresh()->sync_status)->toBe(WebsiteSyncStatus::Synced);
    });

    it('counts a new image as the product changing', function () {
        webhookTestEndpoint($this->website);
        $selection = webhookTestSelection($this->website);

        WebsiteProduct::query()->whereKey($selection->id)->toBase()->update([
            'sync_status' => WebsiteSyncStatus::Synced->value,
            'last_synced_at' => now()->subHour(),
        ]);
        $selection->product->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();

        ProductMedia::create([
            'product_id' => $selection->product_id,
            'type' => ProductMedia::TYPE_IMAGE,
            'disk' => 'public',
            'path' => 'products/front.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'position' => 1,
        ]);

        expect($selection->product->refresh()->updated_at->isAfter(now()->subMinute()))->toBeTrue()
            ->and(app(SyncWebsiteCatalogue::class)->sweep())->toBe(1);
    });

    it('marks what a storefront read as synchronised without making it look changed', function () {
        [$credential, $secret] = storefrontCredential($this->website);
        $selection = webhookTestSelection($this->website);

        WebsiteProduct::query()->whereKey($selection->id)->toBase()->update(['updated_at' => now()->subMinute()]);
        $updatedAt = $selection->refresh()->updated_at->toIso8601String();

        storefrontCall($credential, $secret, 'products')->assertOk();

        $selection->refresh();

        expect($selection->sync_status)->toBe(WebsiteSyncStatus::Synced)
            ->and($selection->updated_at->toIso8601String())->toBe($updatedAt)
            ->and($this->website->refresh()->last_synced_at)->not->toBeNull();
    });

    it('synchronises everything on request, once a minute, writing nothing to the catalogue', function () {
        webhookTestEndpoint($this->website);
        $product = webhookTestSelection($this->website)->product;
        $catalogue = [Product::query()->count(), $product->refresh()->getAttributes()];

        $this->actingAs($this->account->owner)
            ->post(route('websites.sync.store', $this->website->public_id))
            ->assertRedirect(route('websites.integration.show', $this->website->public_id))
            ->assertSessionHasNoErrors();

        $sent = WebhookDelivery::query()->count();

        expect(WebhookDelivery::query()->pluck('event_type')->map->value->all())
            ->toContain('product.updated', 'category.updated');

        $this->actingAs($this->account->owner)
            ->from(route('websites.integration.show', $this->website->public_id))
            ->post(route('websites.sync.store', $this->website->public_id))
            ->assertSessionHasErrors('sync');

        expect(WebhookDelivery::query()->count())->toBe($sent)
            ->and([Product::query()->count(), $product->refresh()->getAttributes()])->toBe($catalogue);
    });
});
