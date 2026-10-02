<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Storage\Data\R2ConnectionTestResult;
use App\Domain\Storage\R2StorageSettings;
use App\Integrations\Storage\R2Manager;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * Settings -> Storage: Cloudflare R2 (beta-critical batch, Commit 3).
 *
 * Mirrors tests/Feature/Payment/GatewayConfigurationTest.php's "the settings
 * screen" rules exactly -- a secret never comes back out, a blank field
 * means "leave it", saving and switching on both sit behind a freshly
 * confirmed password -- plus the one rule unique to this screen: a save is
 * tested against R2, live, before anything is written, and a failed test
 * leaves whatever was already stored completely untouched.
 *
 * R2Manager::testConnection() is mocked throughout -- a feature test must
 * never make a real network call to R2.
 */

function storageSettingsTestCredentials(): array
{
    return [
        'account_id' => 'acct-123',
        'access_key_id' => 'AKIAEXAMPLE',
        'secret_access_key' => 'super-secret-value',
        'bucket' => 'feriwala-media',
        'endpoint' => 'https://acct-123.r2.cloudflarestorage.com',
        'region' => 'auto',
        'public_domain' => 'https://cdn.example.com',
        'default_visibility' => 'private',
        'signed_url_expiry_minutes' => 15,
        'reason' => 'Initial R2 setup',
    ];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = testPlatformStaff(PlatformRole::SystemAdministrator);
});

describe('the settings screen', function () {
    it('shows an unconfigured, switched-off state with nothing stored', function () {
        $this->actingAs($this->admin)
            ->get(route('admin.storage-settings.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/storage-settings/index')
                ->where('settings.enabled', false)
                ->where('settings.is_configured', false)
                ->where('settings.access_key_id_masked', null)
                ->where('can.manage', true));
    });

    it('never sends a credential to the browser', function () {
        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::success()));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials())
            ->assertSessionHasNoErrors();

        $response = $this->actingAs($this->admin)->get(route('admin.storage-settings.index'));

        $response->assertOk()
            ->assertDontSee('super-secret-value', escape: false)
            ->assertDontSee('AKIAEXAMPLE', escape: false)
            ->assertInertia(fn ($page) => $page
                ->where('settings.is_access_key_configured', true)
                ->where('settings.is_secret_configured', true)
                ->where('settings.access_key_id_masked', '••••MPLE')
                ->missing('settings.access_key_id')
                ->missing('settings.secret_access_key'));
    });

    it('is closed to a staff member without an integration permission', function () {
        $viewer = testPlatformStaff(PlatformRole::OrderManager);

        $this->actingAs($viewer)
            ->get(route('admin.storage-settings.index'))
            ->assertForbidden();
    });
});

describe('saving credentials', function () {
    it('tests the connection live before saving anything', function () {
        $this->mock(R2Manager::class, function ($mock) {
            $mock->shouldReceive('testConnection')
                ->once()
                ->andReturn(R2ConnectionTestResult::success());
        });

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stored = Setting::query()->where('key', R2StorageSettings::SECRET_ACCESS_KEY)->value('value');

        expect($stored)->not->toBe('super-secret-value')
            ->and(app(SettingsRepository::class)->get(R2StorageSettings::SECRET_ACCESS_KEY))
            ->toBe('super-secret-value')
            ->and(app(R2StorageSettings::class)->isConfigured())->toBeTrue();
    });

    it('persists nothing at all when the live test fails', function () {
        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::failure('Access denied.')));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials())
            ->assertSessionHasErrors('account_id');

        expect(Setting::query()->where('key', R2StorageSettings::ACCESS_KEY_ID)->exists())->toBeFalse()
            ->and(app(R2StorageSettings::class)->isConfigured())->toBeFalse();
    });

    it('preserves a previously working configuration when a later save fails its test', function () {
        // Seeded directly rather than through the HTTP endpoint: Laravel's
        // router caches a dispatched route's resolved controller instance,
        // so two sequential requests to the same route within one test
        // would otherwise share the first request's already-injected
        // (and by then stale) R2Manager mock.
        app(SettingsRepository::class)->define(R2StorageSettings::BUCKET, 'storage', SettingType::String);
        app(SettingsRepository::class)->set(R2StorageSettings::BUCKET, 'feriwala-media');

        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::failure('Bucket not found.')));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), [
                ...storageSettingsTestCredentials(),
                'bucket' => 'a-bucket-that-does-not-exist',
            ])
            ->assertSessionHasErrors();

        // The previously stored bucket is exactly what is still stored.
        expect(app(SettingsRepository::class)->get(R2StorageSettings::BUCKET))->toBe('feriwala-media');
    });

    it('keeps the stored secret when the field is left blank', function () {
        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::success()));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials());

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), [
                ...storageSettingsTestCredentials(),
                'access_key_id' => '',
                'secret_access_key' => '',
                'reason' => 'Bucket name tweak',
                'bucket' => 'feriwala-media-v2',
            ])
            ->assertSessionHasNoErrors();

        expect(app(SettingsRepository::class)->get(R2StorageSettings::SECRET_ACCESS_KEY))
            ->toBe('super-secret-value')
            ->and(app(SettingsRepository::class)->get(R2StorageSettings::BUCKET))
            ->toBe('feriwala-media-v2');
    });

    it('records only the names of the fields that changed, never their values', function () {
        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::success()));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials());

        $entry = AuditLog::query()->where('action', 'storage.r2_configured')->firstOrFail();

        expect(json_encode($entry->after))->not->toContain('super-secret-value')
            ->and($entry->after['credentials_set'])->toContain('secret_access_key', 'access_key_id', 'bucket');
    });

    it('refuses without a freshly confirmed password', function () {
        $this->actingAs($this->admin)
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials())
            ->assertRedirect(route('password.confirm'));

        expect(Setting::query()->where('key', R2StorageSettings::BUCKET)->exists())->toBeFalse();
    });

    it('is closed to a staff member without the integration-management permission', function () {
        $viewer = testPlatformStaff(PlatformRole::OrderManager);

        $this->actingAs($viewer)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials())
            ->assertForbidden();
    });
});

describe('switching R2 on', function () {
    it('refuses while R2 is not fully configured', function () {
        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.storage-settings.enable'), [
                'enabled' => true,
                'reason' => 'Go live',
            ])
            ->assertSessionHasErrors('enabled');

        expect(app(R2StorageSettings::class)->isEnabled())->toBeFalse();
    });

    it('switches on once fully configured', function () {
        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::success()));

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.storage-settings.update'), storageSettingsTestCredentials());

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put(route('admin.storage-settings.enable'), [
                'enabled' => true,
                'reason' => 'Go live',
            ])
            ->assertRedirect();

        expect(app(R2StorageSettings::class)->isEnabled())->toBeTrue()
            ->and(AuditLog::query()->where('action', 'storage.r2_enabled')->exists())->toBeTrue();
    });
});

describe('testing a connection', function () {
    it('reports success without persisting anything', function () {
        $this->mock(R2Manager::class, fn ($mock) => $mock
            ->shouldReceive('testConnection')
            ->andReturn(R2ConnectionTestResult::success('Connected.')));

        $this->actingAs($this->admin)
            ->post(route('admin.storage-settings.test-connection'), storageSettingsTestCredentials())
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Connected.']);

        expect(Setting::query()->where('key', R2StorageSettings::BUCKET)->exists())->toBeFalse();
    });

    it('reports a clear reason when required fields are still missing', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.storage-settings.test-connection'), [])
            ->assertOk()
            ->assertJson(['success' => false]);
    });

    it('is closed to a staff member without the integration-management permission', function () {
        $viewer = testPlatformStaff(PlatformRole::OrderManager);

        $this->actingAs($viewer)
            ->post(route('admin.storage-settings.test-connection'), storageSettingsTestCredentials())
            ->assertForbidden();
    });
});
