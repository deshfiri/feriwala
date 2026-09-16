<?php

use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Models\User;

/**
 * A partner issuing, rotating and revoking their storefront's credentials
 * (§17.3, contract §3.1, P5-17).
 *
 * The secret is the one thing on this screen that must never be seen twice: it
 * is shown once, in the response that made it, and stored where only the
 * signature check can use it.
 */
beforeEach(function () {
    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
});

it('issues a credential with the chosen scopes and shows the secret once', function () {
    $response = $this->actingAs($this->account->owner)
        ->post(route('websites.credentials.store', $this->website->public_id), [
            'name' => 'Production storefront',
            'scopes' => ['catalog:read', 'inventory:read'],
        ]);

    $response->assertRedirect(route('websites.integration.show', $this->website->public_id));

    $credential = WebsiteCredential::query()->firstOrFail();

    expect($credential->key_id)->toStartWith('wsk_')
        ->and($credential->scopes)->toBe(['catalog:read', 'inventory:read']);

    // The secret is encrypted at rest: the raw column is not the secret.
    $raw = DB::table('website_credentials')->where('id', $credential->id)->value('secret');

    expect($raw)->not->toBe($credential->secret)
        ->and(strlen($credential->secret))->toBe(64);

    /*
     * The page the redirect lands on is the one time the secret is shown, as
     * flash data; a reload's page carries it nowhere — not as flash, not in a
     * prop.
     *
     * Read from the page object each response builds rather than the HTML
     * shell: within one test process the root component can embed an earlier
     * request's page, which a browser — a fresh request each time — never sees.
     */
    $landing = $this->actingAs($this->account->owner)
        ->get(route('websites.integration.show', $this->website->public_id));

    $landing->assertOk();

    expect($landing->viewData('page')['flash']['credential']['secret'] ?? null)->toBe($credential->secret)
        ->and(json_encode($landing->viewData('page')['props']))->not->toContain($credential->secret);

    $reload = $this->actingAs($this->account->owner)
        ->get(route('websites.integration.show', $this->website->public_id));

    $reload->assertOk();

    expect(json_encode($reload->viewData('page')))->not->toContain($credential->secret);
});

it('never writes the secret into the audit trail', function () {
    $this->actingAs($this->account->owner)
        ->post(route('websites.credentials.store', $this->website->public_id), [
            'name' => 'Production storefront',
            'scopes' => ['catalog:read'],
        ]);

    $credential = WebsiteCredential::query()->firstOrFail();

    expect(AuditLog::query()->where('action', 'website.credential_issued')->exists())->toBeTrue()
        ->and(AuditLog::query()->get()->contains(fn (AuditLog $log) => str_contains((string) json_encode($log->toArray()), $credential->secret)))
        ->toBeFalse();
});

it('refuses a scope the contract does not have', function () {
    $this->actingAs($this->account->owner)
        ->from(route('websites.integration.show', $this->website->public_id))
        ->post(route('websites.credentials.store', $this->website->public_id), [
            'name' => 'Greedy',
            'scopes' => ['wallet:read'],
        ])
        ->assertSessionHasErrors('scopes.0');

    expect(WebsiteCredential::query()->count())->toBe(0);
});

it('rotates a secret, keeping the previous one for its window', function () {
    [$credential, $secret] = storefrontCredential($this->website);

    $this->actingAs($this->account->owner)
        ->post(route('websites.credentials.rotate', [$this->website->public_id, $credential->public_id]))
        ->assertRedirect();

    $credential->refresh();

    expect($credential->secret)->not->toBe($secret)
        ->and($credential->previous_secret)->toBe($secret)
        ->and($credential->previous_secret_expires_at)->not->toBeNull();
});

it('revokes with a reason, and only with one', function () {
    [$credential] = storefrontCredential($this->website);

    $this->actingAs($this->account->owner)
        ->from(route('websites.integration.show', $this->website->public_id))
        ->post(route('websites.credentials.revoke', [$this->website->public_id, $credential->public_id]), [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->account->owner)
        ->post(route('websites.credentials.revoke', [$this->website->public_id, $credential->public_id]), [
            'reason' => 'Posted in a public repository.',
        ])
        ->assertRedirect();

    expect($credential->refresh()->revoked_at)->not->toBeNull()
        ->and($credential->previous_secret)->toBeNull();
});

it('is refused for another account\'s website and for a staff member', function () {
    $theirs = Website::factory()->forAccount(websiteTestAccount())->active()->create();

    $this->actingAs($this->account->owner)
        ->get(route('websites.integration.show', $theirs->public_id))
        ->assertNotFound();

    $staff = User::factory()->staffOf($this->account, AccountRole::Staff)->create();

    $this->actingAs($staff)
        ->post(route('websites.credentials.store', $this->website->public_id), [
            'name' => 'Staff key',
            'scopes' => ['catalog:read'],
        ])
        ->assertForbidden();
});
