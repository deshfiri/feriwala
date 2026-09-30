<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Account\Enums\AccountStatus;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Settings\AccentColor;
use App\Domain\Settings\Actions\ManageBranding;
use App\Domain\Settings\Branding;
use App\Domain\Settings\Enums\BrandingAsset;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The platform's logo and browser icon.
 *
 * Stored as managed paths in the existing settings table, served as addresses
 * through one shared contract, and always falling back to the files shipped in
 * `public/` when there is nothing safe to serve.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Storage::fake(Branding::DISK);

    $this->admin = testPlatformStaff(PlatformRole::SuperAdmin);
});

function brandingImage(string $name = 'logo.png', int $kilobytes = 20): UploadedFile
{
    return UploadedFile::fake()->image($name, 400, 120)->size($kilobytes);
}

function brandingSvg(string $name = 'logo.svg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
    );
}

/**
 * A real uploaded file whose type is decided by its bytes.
 *
 * Laravel's fake uploads report a MIME type from the file name, so they cannot
 * show that a disguised file is refused; this one goes through the same content
 * sniffing a browser upload does.
 */
function brandingRealUpload(string $name, string $contents): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'branding');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

function brandingStoredPath(BrandingAsset $asset): mixed
{
    return app(SettingsRepository::class)->get($asset->setting());
}

/**
 * The page's opening `<html>` tag — where the site-wide accent is painted.
 * Read on its own, because the Branding page's own preview swatches carry
 * `--brand-base` inline further down the rendered page.
 */
function brandingRootTag(string $html): string
{
    preg_match('/<html\b[^>]*>/', $html, $matches);

    return $matches[0] ?? '';
}

describe('the shipped defaults', function () {
    it('serves the shipped logo and icon when nothing has been uploaded', function () {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.logo_url', '/logo.png')
                ->where('branding.favicon_url', '/favicon.svg')
                ->where('branding.favicon_type', 'image/svg+xml'),
            );
    });

    it('renders exactly one browser icon link, keyed for the client to adopt', function () {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        expect(substr_count($html, 'rel="icon"'))->toBe(1)
            ->and($html)->toContain('<link rel="icon" href="/favicon.svg" type="image/svg+xml" data-inertia="favicon">');
    });

    it('falls back when the uploaded file has gone missing from the disk', function () {
        $settings = app(SettingsRepository::class);
        $settings->define(BrandingAsset::Logo->setting(), 'branding', SettingType::String);
        $settings->set(BrandingAsset::Logo->setting(), 'branding/logo-'.str_repeat('a', 32).'.png');

        expect(app(Branding::class)->url(BrandingAsset::Logo))->toBe('/logo.png')
            ->and(app(Branding::class)->isCustom(BrandingAsset::Logo))->toBeFalse();
    });

    it('never serves a value that is not a path this feature wrote', function (string $value) {
        $settings = app(SettingsRepository::class);
        $settings->define(BrandingAsset::Favicon->setting(), 'branding', SettingType::String);
        $settings->set(BrandingAsset::Favicon->setting(), $value);
        Storage::disk(Branding::DISK)->put('elsewhere.png', 'x');

        expect(app(Branding::class)->toArray()['favicon_url'])->toBe('/favicon.svg');
    })->with([
        'a data URI' => 'data:image/png;base64,iVBORw0KGgo=',
        'a path outside the folder' => 'elsewhere.png',
        'a climbing path' => 'branding/../elsewhere.png',
        'an svg' => 'branding/icon-abc.svg',
    ]);

    it('falls back when the settings store cannot be reached', function () {
        $this->mock(SettingsRepository::class, fn ($mock) => $mock
            ->shouldReceive('get')
            ->andThrow(new RuntimeException('The settings store is unavailable.')));

        expect(app(Branding::class)->toArray())->toBe([
            'logo_url' => '/logo.png',
            'favicon_url' => '/favicon.svg',
            'favicon_type' => 'image/svg+xml',
            'accent' => null,
        ]);
    });
});

describe('uploading and restoring', function () {
    it('stores an uploaded logo as a managed path on the public disk and serves its address', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update', 'logo'), ['file' => brandingImage()])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $path = brandingStoredPath(BrandingAsset::Logo);

        expect($path)->toMatch('#^branding/logo-[a-f0-9]{32}\.png$#');
        Storage::disk(Branding::DISK)->assertExists($path);

        $this->actingAs($this->admin)
            ->get(route('admin.branding.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/branding')
                ->where('branding.logo_url', Storage::disk(Branding::DISK)->url($path))
                ->where('assets.0.is_custom', true),
            );
    });

    it('replaces a logo and removes the file it replaced', function () {
        $this->actingAs($this->admin)->post(route('admin.branding.update', 'logo'), ['file' => brandingImage('first.png')]);
        $first = brandingStoredPath(BrandingAsset::Logo);

        $this->actingAs($this->admin)->post(route('admin.branding.update', 'logo'), ['file' => brandingImage('second.png')]);
        $second = brandingStoredPath(BrandingAsset::Logo);

        expect($second)->not->toBe($first);
        Storage::disk(Branding::DISK)->assertMissing($first);
        Storage::disk(Branding::DISK)->assertExists($second);
    });

    it('uploads a browser icon, and the document head carries it once', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update', 'favicon'), ['file' => UploadedFile::fake()->image('icon.png', 64, 64)])
            ->assertSessionHasNoErrors();

        $url = Storage::disk(Branding::DISK)->url(brandingStoredPath(BrandingAsset::Favicon));
        $html = (string) $this->actingAs($this->admin)->get(route('admin.branding.edit'))->getContent();

        expect(substr_count($html, 'rel="icon"'))->toBe(1)
            ->and($html)->toContain('href="'.$url.'" type="image/png" data-inertia="favicon"');
    });

    it('restores the shipped default and removes the uploaded file', function () {
        $this->actingAs($this->admin)->post(route('admin.branding.update', 'logo'), ['file' => brandingImage()]);
        $path = brandingStoredPath(BrandingAsset::Logo);

        $this->actingAs($this->admin)
            ->delete(route('admin.branding.destroy', 'logo'))
            ->assertSessionHasNoErrors();

        expect(brandingStoredPath(BrandingAsset::Logo))->toBeNull();
        Storage::disk(Branding::DISK)->assertMissing($path);

        $this->actingAs($this->admin)
            ->get(route('admin.branding.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', '/logo.png'));
    });

    it('clears the settings cache the moment an image is saved', function () {
        app(SettingsRepository::class)->all();

        $this->actingAs($this->admin)->post(route('admin.branding.update', 'logo'), ['file' => brandingImage()]);

        $path = brandingStoredPath(BrandingAsset::Logo);
        $cached = Cache::get(SettingsRepository::CACHE_KEY);

        // Settings are cached as plain values with their type beside them, so a
        // store that unserializes no classes can read them back.
        expect(is_array($cached) ? ($cached[BrandingAsset::Logo->setting()]['value'] ?? null) : $path)->toBe($path)
            ->and(app(Branding::class)->url(BrandingAsset::Logo))->toBe(Storage::disk(Branding::DISK)->url($path));
    });
});

describe('who may change it', function () {
    it('lets a system administrator manage the branding', function () {
        $systemAdministrator = testPlatformStaff(PlatformRole::SystemAdministrator);

        $this->actingAs($systemAdministrator)->get(route('admin.branding.edit'))->assertOk();
        $this->actingAs($systemAdministrator)
            ->post(route('admin.branding.update', 'logo'), ['file' => brandingImage()])
            ->assertSessionHasNoErrors();

        expect(brandingStoredPath(BrandingAsset::Logo))->not->toBeNull();
    });

    it('refuses staff without the permission and business users, before reading the file', function (Closure $identity) {
        $user = $identity();

        $this->actingAs($user)->get(route('admin.branding.edit'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.branding.update', 'logo'), ['file' => brandingSvg()])->assertForbidden()->assertSessionDoesntHaveErrors();
        $this->actingAs($user)->delete(route('admin.branding.destroy', 'favicon'))->assertForbidden();

        expect(Storage::disk(Branding::DISK)->allFiles())->toBe([]);
    })->with([
        'sms manager' => fn () => testPlatformStaff(PlatformRole::SmsManager),
        'product manager' => fn () => testPlatformStaff(PlatformRole::ProductManager),
        'business owner' => fn () => testBusinessAccount(AccountStatus::Active)->owner,
    ]);

    it('refuses the action itself for an unauthorised actor', function () {
        expect(fn () => app(ManageBranding::class)->replace(testPlatformStaff(PlatformRole::SmsManager), BrandingAsset::Logo, brandingImage()))
            ->toThrow(AuthorizationException::class);

        expect(Storage::disk(Branding::DISK)->allFiles())->toBe([]);
    });

    it('shows the navigation link to those who may use it, and to nobody else', function () {
        $permissions = fn ($user) => $this->actingAs($user)->get(route('admin.kyc.index'))->viewData('page')['props']['permissions'] ?? [];

        expect($permissions(testPlatformStaff(PlatformRole::SuperAdmin))['system.manage_settings'] ?? null)->toBeTrue()
            ->and($permissions(testPlatformStaff(PlatformRole::KycManager))['system.manage_settings'] ?? null)->toBeFalse();
    });
});

describe('what is accepted', function () {
    it('refuses an SVG, including one renamed to look like a PNG', function (Closure $file) {
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update', 'logo'), ['file' => $file()])
            ->assertSessionHasErrors('file');

        expect(brandingStoredPath(BrandingAsset::Logo))->toBeNull()
            ->and(Storage::disk(Branding::DISK)->allFiles())->toBe([]);
    })->with([
        'an svg' => fn () => brandingSvg(),
        'an svg named .png' => fn () => brandingRealUpload(
            'logo.png',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
        ),
    ]);

    it('refuses a file larger than each image allows', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update', 'logo'), ['file' => brandingImage('big.png', 4096)])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->admin)
            ->post(route('admin.branding.update', 'favicon'), ['file' => brandingImage('big-icon.png', 600)])
            ->assertSessionHasErrors('file');

        expect(Storage::disk(Branding::DISK)->allFiles())->toBe([]);
    });

    it('refuses something that is not an image at all', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.branding.update', 'logo'), ['file' => brandingRealUpload('logo.png', '<?php echo "hello";')])
            ->assertSessionHasErrors('file');

        expect(Storage::disk(Branding::DISK)->allFiles())->toBe([]);
    });

    it('refuses an unsafe type in the action even when validation was skipped', function () {
        expect(fn () => app(ManageBranding::class)->replace($this->admin, BrandingAsset::Logo, brandingSvg()))
            ->toThrow(InvalidArgumentException::class);

        expect(Storage::disk(Branding::DISK)->allFiles())->toBe([]);
    });
});

describe('the accent colour', function () {
    it('uses the stylesheet accent and writes nothing into the page until one is chosen', function () {
        $response = $this->actingAs($this->admin)->get(route('admin.branding.edit'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('branding.accent', null)
            ->where('accent.color', AccentColor::DEFAULT_HEX)
            ->where('accent.is_custom', false),
        );

        expect(brandingRootTag((string) $response->getContent()))->not->toContain('--brand-base');
    });

    it('saves a chosen colour, shares it with every page and paints it before first load', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.branding.accent.update'), ['accent_color' => '#1D4ED8'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(app(SettingsRepository::class)->get(Branding::ACCENT_SETTING))->toBe('#1d4ed8');

        $response = $this->actingAs($this->admin)->get(route('admin.branding.edit'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('branding.accent.color', '#1d4ed8')
            ->where('branding.accent.on', AccentColor::LIGHT_TEXT),
        );

        expect(brandingRootTag((string) $response->getContent()))->toContain('--brand-base: #1d4ed8;');
    });

    it('lays dark text on a pale accent and flags it as hard to read', function () {
        $pale = AccentColor::fromHex('#fde047');

        expect($pale->onColor())->toBe(AccentColor::DARK_TEXT)
            ->and($pale->isReadableAsText())->toBeFalse()
            ->and(AccentColor::default()->onColor())->toBe(AccentColor::LIGHT_TEXT)
            ->and(AccentColor::fromHex('#1d4ed8')->isReadableAsText())->toBeTrue();
    });

    it('refuses anything that is not a six-digit hex, so nothing else reaches the style attribute', function (string $value) {
        $this->actingAs($this->admin)
            ->put(route('admin.branding.accent.update'), ['accent_color' => $value])
            ->assertSessionHasErrors('accent_color');

        expect(app(SettingsRepository::class)->get(Branding::ACCENT_SETTING))->toBeNull();
    })->with([
        'three digits' => '#fff',
        'no hash' => 'e11d48',
        'a name' => 'red',
        'a declaration' => '#e11d48; background: url(x)',
        'a closing tag' => '"><script>alert(1)</script>',
    ]);

    it('ignores a malformed stored value rather than writing it into the page', function () {
        app(SettingsRepository::class)->define(Branding::ACCENT_SETTING, 'branding', SettingType::String);
        app(SettingsRepository::class)->set(Branding::ACCENT_SETTING, 'red;}</style><script>');

        $response = $this->get(route('login'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->where('branding.accent', null));
        expect(brandingRootTag((string) $response->getContent()))->not->toContain('--brand-base');
    });

    it('restores the default accent and records both changes in the audit log', function () {
        $this->actingAs($this->admin)->put(route('admin.branding.accent.update'), ['accent_color' => '#059669']);

        $this->actingAs($this->admin)
            ->delete(route('admin.branding.accent.destroy'))
            ->assertRedirect();

        expect(app(SettingsRepository::class)->get(Branding::ACCENT_SETTING))->toBeNull()
            ->and(app(Branding::class)->toArray()['accent'])->toBeNull();

        $changed = AuditLog::query()->where('action', 'system.branding_accent_changed')->firstOrFail();
        $restored = AuditLog::query()->where('action', 'system.branding_accent_restored')->firstOrFail();

        expect($changed->actor_id)->toBe($this->admin->id)
            ->and($restored->actor_id)->toBe($this->admin->id);
    });

    it('refuses the accent to anyone who may not manage the branding', function (Closure $identity) {
        $user = $identity();

        $this->actingAs($user)->put(route('admin.branding.accent.update'), ['accent_color' => '#059669'])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.branding.accent.destroy'))->assertForbidden();

        expect(app(SettingsRepository::class)->get(Branding::ACCENT_SETTING))->toBeNull()
            ->and(fn () => app(ManageBranding::class)->setAccent($user, AccentColor::fromHex('#059669')))
            ->toThrow(AuthorizationException::class);
    })->with([
        'sms manager' => fn () => testPlatformStaff(PlatformRole::SmsManager),
        'business owner' => fn () => testBusinessAccount(AccountStatus::Active)->owner,
    ]);
});

describe('the shared contract', function () {
    it('gives the browser addresses only, never a storage path', function () {
        $this->actingAs($this->admin)->post(route('admin.branding.update', 'logo'), ['file' => brandingImage()]);

        $props = $this->actingAs($this->admin)->get(route('admin.branding.edit'))->viewData('page')['props'];

        expect(array_keys($props['branding']))->toBe(['logo_url', 'favicon_url', 'favicon_type', 'accent'])
            ->and(Branding::isManagedPath($props['branding']['logo_url']))->toBeFalse()
            ->and($props['branding']['logo_url'])->toStartWith(rtrim(Storage::disk(Branding::DISK)->url(''), '/'))
            ->and(collect($props['assets'])->pluck('url')->filter(fn (string $url) => Branding::isManagedPath($url)))->toBeEmpty();
    });
});
