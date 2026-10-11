<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\ExternalStore\Enums\ExternalStoreStatus;
use App\Domain\ExternalStore\Jobs\SyncExternalStore;
use App\Domain\ExternalStore\Models\ExternalOrder;
use App\Domain\ExternalStore\Models\ExternalStore;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Connecting a partner's own DFCOMMERCE store (D29): the package, the address,
 * the credentials, the one-live-owner rule, and that nothing secret ever comes
 * back out.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    externalStoreFakeDns();

    $this->account = externalStoreAccount(limit: 2);
    $this->owner = $this->account->owner;
});

/**
 * @return array<string, string>
 */
function externalStoreConnectForm(array $overrides = []): array
{
    return [
        'platform' => 'dfcommerce',
        'name' => 'Khalid Fashion',
        'base_url' => 'https://shop.example.com/api/v1/',
        'api_key' => 'live-key-9876',
        'api_secret' => 'live-secret-very-private',
        ...$overrides,
    ];
}

describe('connecting a store', function () {
    it('verifies the credentials, saves the connection under the account and queues the first import', function () {
        Queue::fake([SyncExternalStore::class]);
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);

        $response = $this->actingAs($this->owner)->post(route('stores.store'), externalStoreConnectForm());

        $store = ExternalStore::query()->sole();

        $response->assertRedirect(route('stores.show', $store->public_id))
            ->assertInertiaFlash('webhook_secret');

        expect($store->business_account_id)->toBe($this->account->id)
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

    it('says why the store refused the credentials, without saving anything', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => Http::response(['ok' => false, 'error' => ['message' => 'bad key live-secret-very-private']], 401)]);

        $this->actingAs($this->owner)
            ->from(route('stores.create'))
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertSessionHasErrors(['connection' => __('stores.refused.verification_failed', ['reason' => __('stores.errors.credentials_refused')])]);

        expect(ExternalStore::query()->count())->toBe(0);
        // The store's own words — which echo the secret here — are never repeated.
        expect(session('errors')->first('connection'))->not->toContain('live-secret-very-private');
    });

    it('checks a connection without saving it', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);

        $this->actingAs($this->owner)
            ->from(route('stores.create'))
            ->post(route('stores.check'), externalStoreConnectForm())
            ->assertSessionHasNoErrors();

        expect(ExternalStore::query()->count())->toBe(0);
    });

    it('refuses a plain HTTP address before calling anything', function () {
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('stores.store'), externalStoreConnectForm(['base_url' => 'http://shop.example.com']))
            ->assertSessionHasErrors(['base_url' => __('stores.refused.address_invalid')]);

        Http::assertNothingSent();
    });

    it('refuses an address that resolves to a private network', function (string $address) {
        externalStoreFakeDns($address);
        Http::fake();

        $this->actingAs($this->owner)
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertSessionHasErrors(['connection' => __('stores.refused.verification_failed', ['reason' => __('stores.errors.address_refused')])]);

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
        $account = externalStoreAccount(entitled: false);

        $this->actingAs($account->owner)
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertSessionHasErrors(['store' => __('stores.refused.not_entitled')]);

        Http::assertNothingSent();
    });

    it('refuses a connection beyond the package limit', function () {
        Http::fake();
        externalStoreConnected($this->account, 'one.example.com');
        externalStoreConnected($this->account, 'two.example.com');

        $this->actingAs($this->owner)
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertSessionHasErrors(['store' => __('stores.refused.limit_reached', ['limit' => 2])]);
    });

    it('does not count a disconnected store against the limit', function () {
        Queue::fake([SyncExternalStore::class]);
        Http::fake(['https://shop.example.com/api/v1/orders*' => externalStoreOrdersPage([])]);
        externalStoreConnected($this->account, 'one.example.com');
        externalStoreConnected($this->account, 'two.example.com', ExternalStoreStatus::Disconnected);

        $this->actingAs($this->owner)
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertSessionHasNoErrors();

        expect(ExternalStore::query()->live()->count())->toBe(2);
    });

    it('lets only one live account hold a store, without saying whose it is', function () {
        Http::fake();
        externalStoreConnected(externalStoreAccount(), 'shop.example.com');

        $this->actingAs($this->owner)
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertSessionHasErrors(['base_url' => __('stores.refused.already_connected')]);

        expect(ExternalStore::query()->where('business_account_id', $this->account->id)->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('lets only someone who manages stores connect one', function (AccountRole $role) {
        $member = User::factory()->staffOf($this->account, $role)->create();

        $this->actingAs($member)
            ->post(route('stores.store'), externalStoreConnectForm())
            ->assertForbidden();
    })->with([AccountRole::Manager, AccountRole::Staff]);
});

describe('the store pages', function () {
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
                ->where('allowance.limit', 2)
                ->where('allowance.used', 1));
    });

    it('never puts a credential in the store page', function () {
        $store = externalStoreConnected($this->account);

        $response = $this->actingAs($this->owner)->get(route('stores.show', $store->public_id));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.api_key_hint', '1234')
                ->where('store.webhook.url', route('webhooks.stores', $store->public_id)));

        expect($response->getContent())
            ->not->toContain('secret-5678')
            ->not->toContain('webhook-secret')
            ->not->toContain('key-1234');
    });

    it('does not show another account\'s store', function (string $route) {
        $theirs = externalStoreConnected(externalStoreAccount(), 'other.example.com');

        $this->actingAs($this->owner)->get(route($route, $theirs->public_id))->assertNotFound();
    })->with(['stores.show', 'stores.edit']);

    it('does not let another account act on a store', function () {
        $theirs = externalStoreConnected(externalStoreAccount(), 'other.example.com');

        $this->actingAs($this->owner)->post(route('stores.sync', $theirs->public_id))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('stores.destroy', $theirs->public_id))->assertNotFound();

        expect($theirs->refresh()->status)->toBe(ExternalStoreStatus::Connected);
    });

    it('keeps guests out', function () {
        $this->get(route('stores.index'))->assertRedirect(route('login'));
    });
});

describe('managing a connection', function () {
    it('queues a sync, never running it in the request', function () {
        Queue::fake([SyncExternalStore::class]);
        $store = externalStoreConnected($this->account);

        $this->actingAs($this->owner)->post(route('stores.sync', $store->public_id))->assertSessionHasNoErrors();

        Queue::assertPushed(SyncExternalStore::class, 1);
    });

    it('reports a failed connection test in words, and changes nothing', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => Http::response([], 500)]);
        $store = externalStoreConnected($this->account);

        $this->actingAs($this->owner)
            ->post(route('stores.test', $store->public_id))
            ->assertInertiaFlash('toast.type', 'error');

        expect($store->refresh()->status)->toBe(ExternalStoreStatus::Connected);
    });

    it('replaces credentials only after the new ones work', function () {
        Http::fake(['https://shop.example.com/api/v1/orders*' => Http::response([], 403)]);
        $store = externalStoreConnected($this->account);

        $this->actingAs($this->owner)
            ->put(route('stores.update', $store->public_id), ['name' => 'Renamed', 'api_key' => 'new-key', 'api_secret' => 'new-secret'])
            ->assertSessionHasErrors('connection');

        expect($store->refresh()->api_key)->toBe('key-1234')
            ->and($store->name)->toBe('Khalid Fashion');
    });

    it('disconnects a store, forgetting its credentials and keeping its orders', function () {
        $store = externalStoreConnected($this->account);
        externalStoreRecordOrder($store);

        $this->actingAs($this->owner)
            ->delete(route('stores.destroy', $store->public_id))
            ->assertRedirect(route('stores.show', $store->public_id));

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
        $this->actingAs($this->owner)->delete(route('stores.destroy', $store->public_id));

        $this->actingAs($this->owner)
            ->put(route('stores.update', $store->public_id), ['name' => 'Khalid Fashion', 'api_key' => 'again-key', 'api_secret' => 'again-secret'])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('webhook_secret');

        $store->refresh();

        expect($store->status)->toBe(ExternalStoreStatus::Pending)
            ->and($store->api_secret)->toBe('again-secret')
            ->and($store->webhook_secret)->not->toBe('webhook-secret');

        Queue::assertPushed(SyncExternalStore::class);
    });

    it('refuses to reconnect without credentials', function () {
        $store = externalStoreConnected($this->account);
        $this->actingAs($this->owner)->delete(route('stores.destroy', $store->public_id));

        $this->actingAs($this->owner)
            ->put(route('stores.update', $store->public_id), ['name' => 'Khalid Fashion'])
            ->assertSessionHasErrors(['api_key' => __('stores.refused.credentials_required')]);
    });

    it('makes a new webhook secret that the old one no longer matches', function () {
        $store = externalStoreConnected($this->account);

        $this->actingAs($this->owner)
            ->post(route('stores.webhook-secret.rotate', $store->public_id))
            ->assertInertiaFlash('webhook_secret');

        expect($store->refresh()->webhook_secret)->not->toBe('webhook-secret');
    });
});
