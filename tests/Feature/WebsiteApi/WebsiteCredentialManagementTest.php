<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Website\Models\Website;
use App\Domain\Website\Models\WebsiteCredential;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Issuing, rotating and revoking a storefront's credentials (§17.3, contract
 * §3.1, P5-17), by authorised platform staff only (D32 §2, P3 ER-1).
 *
 * The secret is the one thing here that must never be seen twice: it travels
 * once, in the JSON response that made it, and is stored where only the
 * signature check can use it. The owner sees which credentials exist and never
 * handles one.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->account = websiteTestAccount();
    $this->website = Website::factory()->forAccount($this->account)->active()->create();
    $this->superAdmin = testPlatformStaff(PlatformRole::SuperAdmin);
});

it('issues a credential with the chosen scopes and answers with the secret once', function () {
    $response = $this->actingAs($this->superAdmin)
        ->postJson(route('admin.websites.credentials.store', $this->website->public_id), [
            'name' => 'Production storefront',
            'scopes' => ['catalog:read', 'inventory:read'],
        ]);

    $response->assertCreated()
        ->assertHeader('Pragma', 'no-cache');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $credential = WebsiteCredential::query()->firstOrFail();

    expect($credential->key_id)->toStartWith('wsk_')
        ->and($credential->scopes)->toBe(['catalog:read', 'inventory:read'])
        ->and($credential->created_by)->toBe($this->superAdmin->id)
        ->and($response->json('key_id'))->toBe($credential->key_id)
        ->and($response->json('secret'))->toBe($credential->secret)
        ->and($response->json('env'))
        ->toContain('BANIJ_CREDENTIAL_KEY_ID='.$credential->key_id)
        ->toContain('BANIJ_CREDENTIAL_SECRET='.$credential->secret)
        ->toContain('BANIJ_ERP_BASE_URL=');

    // The secret is encrypted at rest: the raw column is not the secret.
    $raw = DB::table('website_credentials')->where('id', $credential->id)->value('secret');

    expect($raw)->not->toBe($credential->secret)
        ->and(strlen($credential->secret))->toBe(64);

    // Not in the session, so no later page can carry it.
    expect(json_encode(session()->all()))->not->toContain($credential->secret);

    $admin = $this->actingAs($this->superAdmin)
        ->get(route('admin.websites.show', $this->website->public_id));

    $admin->assertOk();

    expect(json_encode($admin->viewData('page')))->not->toContain($credential->secret)
        ->and($admin->viewData('page')['props']['credentials'][0]['key_id'])->toBe($credential->key_id);

    $owner = $this->actingAs($this->account->owner)
        ->get(route('websites.integration.show', $this->website->public_id));

    $owner->assertOk();

    expect(json_encode($owner->viewData('page')))->not->toContain($credential->secret)
        ->and($owner->viewData('page')['props']['credentials'][0]['key_id'])->toBe($credential->key_id);
});

it('records who issued it and never writes the secret into the audit trail', function () {
    $this->actingAs($this->superAdmin)
        ->postJson(route('admin.websites.credentials.store', $this->website->public_id), [
            'name' => 'Production storefront',
            'scopes' => ['catalog:read'],
        ])
        ->assertCreated();

    $credential = WebsiteCredential::query()->firstOrFail();
    $entry = AuditLog::query()->where('action', 'website.credential_issued')->firstOrFail();

    expect($entry->actor_id)->toBe($this->superAdmin->id)
        ->and(AuditLog::query()->get()->contains(fn (AuditLog $log) => str_contains((string) json_encode($log->toArray()), $credential->secret)))
        ->toBeFalse();
});

it('refuses a credential without explicitly chosen scopes, or with one the contract does not have', function (array $scopes, string $field) {
    $this->actingAs($this->superAdmin)
        ->postJson(route('admin.websites.credentials.store', $this->website->public_id), [
            'name' => 'Greedy',
            'scopes' => $scopes,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(WebsiteCredential::query()->count())->toBe(0);
})->with([
    'no scope' => [[], 'scopes'],
    'an unknown scope' => [['wallet:read'], 'scopes.0'],
]);

it('rotates a secret, answering with the new one and keeping the previous one for its window', function () {
    [$credential, $secret] = storefrontCredential($this->website);

    $response = $this->actingAs($this->superAdmin)
        ->postJson(route('admin.websites.credentials.rotate', [$this->website->public_id, $credential->public_id]))
        ->assertCreated();

    $credential->refresh();

    expect($credential->secret)->not->toBe($secret)
        ->and($response->json('secret'))->toBe($credential->secret)
        ->and($response->json('key_id'))->toBe($credential->key_id)
        ->and($credential->previous_secret)->toBe($secret)
        ->and($credential->previous_secret_expires_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'website.credential_rotated')->exists())->toBeTrue();
});

it('revokes with a reason, and only with one', function () {
    [$credential] = storefrontCredential($this->website);

    $this->actingAs($this->superAdmin)
        ->from(route('admin.websites.show', $this->website->public_id))
        ->post(route('admin.websites.credentials.revoke', [$this->website->public_id, $credential->public_id]), [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($this->superAdmin)
        ->post(route('admin.websites.credentials.revoke', [$this->website->public_id, $credential->public_id]), [
            'reason' => 'Posted in a public repository.',
        ])
        ->assertRedirect(route('admin.websites.show', $this->website->public_id));

    expect($credential->refresh()->revoked_at)->not->toBeNull()
        ->and($credential->previous_secret)->toBeNull()
        ->and(AuditLog::query()->where('action', 'website.credential_revoked')->exists())->toBeTrue();
});

it('lets staff explicitly granted website.manage_integrations manage credentials', function () {
    $operator = tap(
        testPlatformStaff(PlatformRole::SystemAdministrator),
        fn (User $staff) => $staff->givePermissionTo('website.manage_integrations'),
    );

    $this->actingAs($operator)
        ->postJson(route('admin.websites.credentials.store', $this->website->public_id), [
            'name' => 'Production storefront',
            'scopes' => ['catalog:read'],
        ])
        ->assertCreated();

    expect(WebsiteCredential::query()->count())->toBe(1);
});

it('refuses anyone without website.manage_integrations, and leaves existing credentials alone', function (string $actor) {
    [$credential, $secret] = storefrontCredential($this->website);
    $who = match ($actor) {
        'an Admin' => testPlatformStaff(PlatformRole::Admin),
        'the owner' => $this->account->owner,
        'account staff' => User::factory()->staffOf($this->account, AccountRole::Staff)->create(),
    };

    $this->actingAs($who)
        ->postJson(route('admin.websites.credentials.store', $this->website->public_id), [
            'name' => 'Sneaky key',
            'scopes' => ['catalog:read'],
        ])
        ->assertForbidden();

    $this->actingAs($who)
        ->postJson(route('admin.websites.credentials.rotate', [$this->website->public_id, $credential->public_id]))
        ->assertForbidden();

    $this->actingAs($who)
        ->post(route('admin.websites.credentials.revoke', [$this->website->public_id, $credential->public_id]), [
            'reason' => 'Trying it on.',
        ])
        ->assertForbidden();

    $credential->refresh();

    expect(WebsiteCredential::query()->count())->toBe(1)
        ->and($credential->secret)->toBe($secret)
        ->and($credential->revoked_at)->toBeNull();
})->with(['an Admin', 'the owner', 'account staff']);

it('withholds the credential panel from staff who may administer the website but not its integration', function () {
    storefrontCredential($this->website);

    $page = $this->actingAs(testPlatformStaff(PlatformRole::Admin))
        ->get(route('admin.websites.show', $this->website->public_id));

    $page->assertOk();

    expect($page->viewData('page')['props']['can']['manage_integration'])->toBeFalse()
        ->and($page->viewData('page')['props']['credentials'])->toBe([])
        ->and($page->viewData('page')['props']['storefront_connection'])->toBeNull();
});

it('will not reach another website\'s credential through this website\'s address', function () {
    $theirs = Website::factory()->forAccount(websiteTestAccount())->active()->create();
    [$credential, $secret] = storefrontCredential($theirs);

    $this->actingAs($this->superAdmin)
        ->postJson(route('admin.websites.credentials.rotate', [$this->website->public_id, $credential->public_id]))
        ->assertNotFound();

    $this->actingAs($this->superAdmin)
        ->post(route('admin.websites.credentials.revoke', [$this->website->public_id, $credential->public_id]), [
            'reason' => 'Wrong website entirely.',
        ])
        ->assertNotFound();

    expect($credential->refresh()->secret)->toBe($secret)
        ->and($credential->revoked_at)->toBeNull();
});

it('gives the owner a read-only view, with no way left to manage credentials', function () {
    $theirs = Website::factory()->forAccount(websiteTestAccount())->active()->create();

    $this->actingAs($this->account->owner)
        ->get(route('websites.integration.show', $theirs->public_id))
        ->assertNotFound();

    expect(Route::has('websites.credentials.store'))->toBeFalse()
        ->and(Route::has('websites.credentials.rotate'))->toBeFalse()
        ->and(Route::has('websites.credentials.revoke'))->toBeFalse();
});
