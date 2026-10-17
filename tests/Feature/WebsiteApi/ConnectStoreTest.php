<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Domain\Website\Models\WebsiteDomain;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * Connecting a partner's storefront in one step, from the admin dashboard
 * (D32): an address in, a store id and a token out — the whole of what the
 * storefront's `.env` needs besides the ERP's own address.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Http::fake();

    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

function connectStore(mixed $test, Website $website, array $body, ?object $as = null): TestResponse
{
    return $test->actingAs($as ?? $test->admin)
        ->postJson(route('admin.websites.connection', $website->public_id), $body);
}

it('connects a store from an address, answering once with a store id, a token and the .env block', function () {
    $response = connectStore($this, $this->website, ['address' => 'https://shop.example.com/']);

    $response->assertCreated()->assertHeader('Pragma', 'no-cache');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $credential = WebsiteCredential::query()->sole();

    expect($response->json('store_id'))->toBe($credential->key_id)
        ->and($response->json('store_id'))->toStartWith('wsk_')
        ->and(strlen((string) $response->json('token')))->toBeGreaterThanOrEqual(32)
        ->and($response->json('address'))->toBe('shop.example.com')
        ->and($response->json('env'))
        ->toContain('ERP_BASE_URL=')
        ->toContain('STORE_ID='.$credential->key_id)
        ->toContain('STORE_TOKEN='.$response->json('token'));
});

it('gives the credential exactly the storefront scopes, and no more', function () {
    connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    expect(WebsiteCredential::query()->sole()->scopes)->toEqualCanonicalizing([
        'catalog:read', 'inventory:read', 'orders:read', 'orders:write', 'customers:write', 'returns:write',
    ]);
});

it('registers the address as the website’s domain', function () {
    connectStore($this, $this->website, ['address' => 'Shop.Example.com'])->assertCreated();

    expect($this->website->refresh()->domain)->toBe('shop.example.com')
        ->and(WebsiteDomain::query()->where('website_id', $this->website->id)->value('domain'))->toBe('shop.example.com');
});

it('never writes the token into the audit trail or the stored credential in the clear', function () {
    $token = connectStore($this, $this->website, ['address' => 'shop.example.com'])->json('token');

    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($token);

    $credential = WebsiteCredential::query()->sole();

    expect(json_encode($credential->getAttributes()))->not->toContain($token);
});

it('accepts a local address as it is when local addresses are allowed, and registers no webhook', function () {
    config(['website.allow_local_addresses' => true]);

    $response = connectStore($this, $this->website, ['address' => 'http://127.0.0.1:8001'])->assertCreated();

    expect($response->json('local'))->toBeTrue()
        ->and($response->json('webhook_url'))->toBeNull()
        ->and($this->website->refresh()->domain)->toBe('127.0.0.1:8001')
        ->and($response->json('env'))->toContain('APP_ENV=local');
});

it('refuses a local address in production', function () {
    config(['website.allow_local_addresses' => false]);

    connectStore($this, $this->website, ['address' => 'http://127.0.0.1:8001'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');

    expect(WebsiteCredential::query()->count())->toBe(0);
});

it('connects a store once; a second attempt is refused, not duplicated', function () {
    connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    connectStore($this, $this->website, ['address' => 'other.example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');

    expect(WebsiteCredential::query()->count())->toBe(1);
});

it('refuses an address that is taken by another website, and leaves nothing half-made', function () {
    Website::factory()->create(['domain' => 'taken.example.com']);

    connectStore($this, $this->website, ['address' => 'taken.example.com'])->assertUnprocessable();

    expect(WebsiteCredential::query()->count())->toBe(0)
        ->and($this->website->refresh()->domain)->toBeNull();
});

it('is for staff who may manage integrations; the partner cannot', function () {
    $this->actingAs($this->account->owner)
        ->postJson(route('admin.websites.connection', $this->website->public_id), ['address' => 'shop.example.com'])
        ->assertForbidden();

    expect(WebsiteCredential::query()->count())->toBe(0);
});

it('records who connected the store and where', function () {
    connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    $entry = AuditLog::query()->where('action', 'website.store_connected')->firstOrFail();

    expect($entry->actor_id)->toBe($this->admin->id)
        ->and($entry->after['address'])->toBe('shop.example.com');
});

it('issues a new token for a connected store and keeps the old one working for the grace window', function () {
    $first = connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    $second = $this->actingAs($this->admin)
        ->postJson(route('admin.websites.connection.rotation', $this->website->public_id))
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache');

    $credential = WebsiteCredential::query()->sole();

    expect($second->json('store_id'))->toBe($first->json('store_id'))
        ->and($second->json('token'))->not->toBe($first->json('token'))
        ->and($second->json('env'))->toContain('STORE_TOKEN='.$second->json('token'))
        ->and($credential->previous_secret_expires_at)->not->toBeNull();
});

it('refuses to rotate a store that is not connected', function () {
    $this->actingAs($this->admin)
        ->postJson(route('admin.websites.connection.rotation', $this->website->public_id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('address');
});

it('disconnects a store: credentials revoked, webhook off, reason recorded', function () {
    connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    $this->actingAs($this->admin)
        ->post(route('admin.websites.connection.disconnection', $this->website->public_id), ['reason' => 'Moved to a new host'])
        ->assertRedirect();

    expect(WebsiteCredential::query()->sole()->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'website.store_disconnected')->firstOrFail()->after['reason'])->toBe('Moved to a new host');

    // And it can be connected again.
    connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    expect(WebsiteCredential::query()->whereNull('revoked_at')->count())->toBe(1);
});

it('requires a reason to disconnect, and the partner cannot rotate or disconnect', function () {
    connectStore($this, $this->website, ['address' => 'shop.example.com'])->assertCreated();

    $this->actingAs($this->admin)
        ->post(route('admin.websites.connection.disconnection', $this->website->public_id), ['reason' => ''])
        ->assertRedirect();

    $this->actingAs($this->account->owner)
        ->postJson(route('admin.websites.connection.rotation', $this->website->public_id))
        ->assertForbidden();

    $this->actingAs($this->account->owner)
        ->post(route('admin.websites.connection.disconnection', $this->website->public_id), ['reason' => 'Because I want to'])
        ->assertForbidden();

    // Without a reason nothing was disconnected; and the partner changed nothing.
    expect(WebsiteCredential::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'website.store_disconnected')->count())->toBe(0);
});
