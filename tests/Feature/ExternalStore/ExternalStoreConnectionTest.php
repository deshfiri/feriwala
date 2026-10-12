<?php

use App\Domain\Account\Models\BusinessAccount;
use App\Domain\ExternalStore\Actions\ConnectExternalStore;
use App\Domain\ExternalStore\Actions\DisconnectExternalStore;
use App\Domain\ExternalStore\Actions\RotateExternalStoreWebhookSecret;
use App\Domain\ExternalStore\Actions\TestExternalStoreConnection;
use App\Domain\ExternalStore\Actions\UpdateExternalStoreConnection;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Enums\StorePlatform;
use App\Domain\ExternalStore\Exceptions\ExternalStoreRefused;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalStore;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * External store connections (D29, as amended by D30).
 *
 * Owners no longer connect stores themselves, so the connection is exercised
 * through the actions that remain — the package, the address, the credentials,
 * the one-live-owner rule, and that nothing secret ever comes back out — and
 * the Owner Panel keeps only read-only pages over what was connected before.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    externalStoreFakeDns();

    $this->account = externalStoreAccount(limit: 2);
    $this->owner = $this->account->owner;
});

/**
 * Connect a DFCOMMERCE store through the action, as the connection workflow
 * would.
 *
 * @return array{store: ExternalStore, webhook_secret: string|null}
 */
function externalStoreConnect(BusinessAccount $account, array $overrides = []): array
{
    $form = [
        'name' => 'Khalid Fashion',
        'base_url' => 'https://shop.example.com/api/v1/',
        'api_key' => 'live-key-9876',
        'api_secret' => 'live-secret-very-private',
        'webhook_secret' => null,
        ...$overrides,
    ];

    return app(ConnectExternalStore::class)->handle(
        $account,
        $account->owner,
        StorePlatform::Dfcommerce,
        $form['name'],
        $form['base_url'],
        $form['api_key'],
        $form['api_secret'],
        $form['webhook_secret'],
    );
}

/**
 * Whether `$store` accepts a notification signed with `$secret`.
 */
function externalStoreAcceptsSecret(ExternalStore $store, string $secret, string $eventId): bool
{
    $body = (string) json_encode(['event' => 'inventory.low_stock', 'data' => []]);

    return test()->call('POST', route('webhooks.stores', $store->public_id), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => externalStoreSignature($body, $secret),
        'HTTP_X_WEBHOOK_EVENT_ID' => $eventId,
    ], content: $body)->isOk();
}

describe('owner self-service is retired (D30)', function () {
    it('no longer offers the owner a way to connect, change or disconnect a store', function (string $name) {
        expect(Route::has($name))->toBeFalse();
    })->with([
        'stores.create', 'stores.check', 'stores.store', 'stores.edit', 'stores.update', 'stores.test',
        'stores.sync', 'stores.webhook-secret.rotate', 'stores.webhook-secret.provide', 'stores.destroy',
    ]);

    it('answers a write to the old addresses with nothing', function () {
        $store = externalStoreConnected($this->account);

        $this->actingAs($this->owner)->post('/stores', ['name' => 'X'])->assertMethodNotAllowed();
        $this->actingAs($this->owner)->post('/stores/'.$store->public_id.'/sync')->assertNotFound();
        $this->actingAs($this->owner)->delete('/stores/'.$store->public_id)->assertMethodNotAllowed();

        expect($store->refresh()->status)->toBe(ExternalStoreStatus::Connected);
    });

    it('keeps what was connected before, its orders and its webhook endpoint', function () {
        $store = externalStoreConnected($this->account);
        externalStoreRecordOrder($store);

        expect(ExternalOrder::query()->where('external_store_id', $store->id)->count())->toBe(1)
            ->and(externalStoreAcceptsSecret($store, 'webhook-secret', 'evt-kept'))->toBeTrue();
    });
});

describe('connecting a store', function () {
    it('verifies the credentials, saves the connection under the account and queues the first import', function () {
        Queue::fake([SyncExternalStore::class]);
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);

        $result = externalStoreConnect($this->account);
        $store = ExternalStore::query()->sole();

        expect($result['webhook_secret'])->toHaveLength(48)
            ->and($store->business_account_id)->toBe($this->account->id)
            ->and($store->status)->toBe(ExternalStoreStatus::Pending)
            ->and($store->base_url)->toBe('https://shop.example.com')
            ->and($store->normalized_host)->toBe('shop.example.com')
            ->and($store->api_secret)->toBe('live-secret-very-private')
            ->and($store->api_key_hint)->toBe('9876')
            ->and($store->statusHistory()->count())->toBe(1);

        // Encrypted at rest: the plain secret is not what the column holds.
        expect(DB::table('external_stores')->value('api_secret'))->not->toContain('live-secret-very-private');

        // The store was asked with the credentials in headers, for one order.
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('X-API-Key', 'live-key-9876')
            && $request->hasHeader('X-API-Secret', 'live-secret-very-private')
            && str_contains($request->url(), 'limit=1'));

        Queue::assertPushed(SyncExternalStore::class, fn (SyncExternalStore $job) => $job->storeId === $store->id);
    });

    it('says why the store refused the credentials, without saving anything or repeating them', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => Http::response(['ok' => false, 'error' => ['message' => 'bad key live-secret-very-private']], 401)]);

        try {
            externalStoreConnect($this->account);
            $this->fail('The connection was not refused.');
        } catch (ExternalStoreRefused $refused) {
            expect($refused->getMessage())
                ->toBe(__('stores.refused.verification_failed', ['reason' => __('stores.errors.credentials_refused')]))
                ->not->toContain('live-secret-very-private');
        }

        expect(ExternalStore::query()->count())->toBe(0);
    });

    it('checks a connection without saving it', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);

        app(ConnectExternalStore::class)->check(
            $this->account, StorePlatform::Dfcommerce, 'https://shop.example.com', 'live-key-9876', 'live-secret-very-private',
        );

        expect(ExternalStore::query()->count())->toBe(0);
    });

    it('refuses a plain HTTP address before calling anything', function () {
        Http::fake();

        expect(fn () => externalStoreConnect($this->account, ['base_url' => 'http://shop.example.com']))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.address_invalid'));

        Http::assertNothingSent();
    });

    it('refuses an address that resolves to a private network', function (string $address) {
        externalStoreFakeDns($address);
        Http::fake();

        expect(fn () => externalStoreConnect($this->account))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.verification_failed', ['reason' => __('stores.errors.address_refused')]));

        Http::assertNothingSent();
        expect(ExternalStore::query()->count())->toBe(0);
    })->with([
        'loopback' => ['127.0.0.1'],
        'private' => ['10.0.0.5'],
        'link-local metadata' => ['169.254.169.254'],
        'carrier-grade NAT' => ['100.64.1.1'],
        'IPv6 loopback' => ['::1'],
        'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
    ]);

    it('refuses a package that does not include external stores', function () {
        Http::fake();

        expect(fn () => externalStoreConnect(externalStoreAccount(entitled: false)))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.not_entitled'));

        Http::assertNothingSent();
    });

    it('refuses a connection beyond the package limit, not counting disconnected stores', function () {
        Queue::fake([SyncExternalStore::class]);
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);
        externalStoreConnected($this->account, 'one.example.com');
        externalStoreConnected($this->account, 'two.example.com', ExternalStoreStatus::Disconnected);

        externalStoreConnect($this->account);
        expect(ExternalStore::query()->live()->count())->toBe(2);

        expect(fn () => externalStoreConnect($this->account, ['base_url' => 'https://three.example.com']))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.limit_reached', ['limit' => 2]));
    });

    it('lets only one live account hold a store, without saying whose it is', function () {
        Http::fake();
        externalStoreConnected(externalStoreAccount(), 'shop.example.com');

        expect(fn () => externalStoreConnect($this->account))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.already_connected'));

        expect(ExternalStore::query()->where('business_account_id', $this->account->id)->count())->toBe(0);
        Http::assertNothingSent();
    });
});

describe('the read-only store pages', function () {
    it('lists the account\'s own stores with their state, and no secret', function () {
        $store = externalStoreConnected($this->account);
        externalStoreConnected(externalStoreAccount(), 'other.example.com');

        $this->actingAs($this->owner)
            ->get(route('stores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stores/index')
                ->has('stores', 1)
                ->where('stores.0.id', $store->public_id)
                ->where('stores.0.status', 'connected')
                ->where('stores.0.orders_count', 0)
                ->missing('allowance'));
    });

    it('never puts a credential in the store page', function () {
        $store = externalStoreConnected($this->account);

        $response = $this->actingAs($this->owner)->get(route('stores.show', $store->public_id));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stores/show')
                ->where('store.webhook.url', route('webhooks.stores', $store->public_id)));

        expect($response->getContent())
            ->not->toContain('secret-5678')
            ->not->toContain('webhook-secret')
            ->not->toContain('key-1234');
    });

    it('does not show another account\'s store', function () {
        $theirs = externalStoreConnected(externalStoreAccount(), 'other.example.com');

        $this->actingAs($this->owner)->get(route('stores.show', $theirs->public_id))->assertNotFound();
    });

    it('keeps guests out', function () {
        $this->get(route('stores.index'))->assertRedirect(route('login'));
    });
});

describe('managing a connection', function () {
    it('reports a failed connection test in words, and changes nothing', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => Http::response([], 500)]);
        $store = externalStoreConnected($this->account);

        $result = app(TestExternalStoreConnection::class)->handle($store);

        expect($result['ok'])->toBeFalse()
            ->and($result['message'])->not->toBeNull()
            ->and($store->refresh()->status)->toBe(ExternalStoreStatus::Connected);
    });

    it('replaces credentials only after the new ones work', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => Http::response([], 403)]);
        $store = externalStoreConnected($this->account);

        expect(fn () => app(UpdateExternalStoreConnection::class)->handle($store, $this->owner, 'Renamed', 'new-key', 'new-secret'))
            ->toThrow(ExternalStoreRefused::class);

        expect($store->refresh()->api_key)->toBe('key-1234')
            ->and($store->name)->toBe('Khalid Fashion');
    });

    it('disconnects a store, forgetting its credentials and keeping its orders', function () {
        $store = externalStoreConnected($this->account);
        externalStoreRecordOrder($store);

        app(DisconnectExternalStore::class)->handle($store, $this->owner, null);
        $store->refresh();

        expect($store->status)->toBe(ExternalStoreStatus::Disconnected)
            ->and($store->api_key)->toBeNull()
            ->and($store->api_secret)->toBeNull()
            ->and($store->webhook_secret)->toBeNull()
            ->and($store->disconnected_at)->not->toBeNull()
            ->and(ExternalOrder::query()->where('external_store_id', $store->id)->count())->toBe(1);
    });

    it('reconnects a disconnected store with working credentials and a new webhook secret', function () {
        Queue::fake([SyncExternalStore::class]);
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);
        $store = externalStoreConnected($this->account);
        app(DisconnectExternalStore::class)->handle($store, $this->owner, null);

        $result = app(UpdateExternalStoreConnection::class)->handle($store->refresh(), $this->owner, 'Khalid Fashion', 'again-key', 'again-secret');
        $store->refresh();

        expect($result['webhook_secret'])->not->toBeNull()
            ->and($store->status)->toBe(ExternalStoreStatus::Pending)
            ->and($store->api_secret)->toBe('again-secret')
            ->and($store->webhook_secret)->not->toBe('webhook-secret');

        Queue::assertPushed(SyncExternalStore::class);
    });

    it('refuses to reconnect without credentials', function () {
        $store = externalStoreConnected($this->account);
        app(DisconnectExternalStore::class)->handle($store, $this->owner, null);

        expect(fn () => app(UpdateExternalStoreConnection::class)->handle($store->refresh(), $this->owner, 'Khalid Fashion', null, null))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.credentials_required'));
    });

    it('makes a new webhook secret that the old one no longer matches', function () {
        $store = externalStoreConnected($this->account);

        $secret = app(RotateExternalStoreWebhookSecret::class)->handle($store, $this->owner);

        expect($store->refresh()->webhook_secret)->toBe($secret)->not->toBe('webhook-secret');
    });
});

describe('a signing secret the store generated', function () {
    it('connects with the store\'s own secret, keeps it encrypted and never hands it back', function () {
        Queue::fake([SyncExternalStore::class]);
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);

        $result = externalStoreConnect($this->account, ['webhook_secret' => 'store-made-secret-0123456789']);
        $store = $result['store']->refresh();

        expect($result['webhook_secret'])->toBeNull()
            ->and($store->webhook_secret)->toBe('store-made-secret-0123456789')
            ->and(DB::table('external_stores')->value('webhook_secret'))->not->toContain('store-made-secret')
            ->and(DB::table('audit_logs')->get()->toJson())->not->toContain('store-made-secret')
            ->and(externalStoreAcceptsSecret($store, 'store-made-secret-0123456789', 'evt-1'))->toBeTrue();
    });

    it('replaces the secret on a connected store: the new one signs, the old one no longer does', function () {
        $store = externalStoreConnected($this->account);

        app(RotateExternalStoreWebhookSecret::class)->provide($store, $this->owner, 'store-made-secret-0123456789');

        expect(DB::table('external_stores')->value('webhook_secret'))->not->toContain('store-made-secret')
            ->and(externalStoreAcceptsSecret($store, 'webhook-secret', 'evt-old'))->toBeFalse()
            ->and(externalStoreAcceptsSecret($store, 'store-made-secret-0123456789', 'evt-new'))->toBeTrue()
            ->and(json_decode((string) DB::table('audit_logs')->where('action', 'external_store.webhook_secret_rotated')->value('after'), true))
            ->toBe(['webhook_signing_origin' => 'store'])
            ->and(DB::table('audit_logs')->get()->toJson())->not->toContain('store-made-secret');
    });

    it('lets a generated secret replace a provided one', function () {
        $store = externalStoreConnected($this->account);
        $rotate = app(RotateExternalStoreWebhookSecret::class);

        $rotate->provide($store, $this->owner, 'store-made-secret-0123456789');
        $generated = $rotate->handle($store->refresh(), $this->owner);

        expect(externalStoreAcceptsSecret($store->refresh(), 'store-made-secret-0123456789', 'evt-old'))->toBeFalse()
            ->and(externalStoreAcceptsSecret($store, $generated, 'evt-new'))->toBeTrue();
    });

    it('refuses a secret for a disconnected store', function () {
        $store = externalStoreConnected($this->account, status: ExternalStoreStatus::Disconnected);

        expect(fn () => app(RotateExternalStoreWebhookSecret::class)->provide($store, $this->owner, 'store-made-secret-0123456789'))
            ->toThrow(ExternalStoreRefused::class, __('stores.refused.not_live'));

        expect($store->refresh()->webhook_secret)->toBeNull();
    });
});
