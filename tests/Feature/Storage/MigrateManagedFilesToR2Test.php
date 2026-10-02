<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Catalog\Actions\ManageProductMedia;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\Enums\SettingType;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Storage\Actions\RunStorageMigration;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\R2StorageSettings;
use App\Integrations\Storage\R2Manager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The R2 migration command (beta-critical batch, Commit 5): moving already-
 * stored local files onto Cloudflare R2 once it is configured, never
 * touching anything outside the handful of tables it knows how to read.
 *
 * R2Manager is mocked throughout to return a locally-faked disk ("r2_test")
 * -- no test here ever makes a real network call to R2.
 */

function storageMigrationEnableR2(): void
{
    $settings = app(SettingsRepository::class);

    $settings->define(R2StorageSettings::ENABLED, 'storage', SettingType::Boolean, true);
    $settings->set(R2StorageSettings::ENABLED, true);
    $settings->define(R2StorageSettings::ACCOUNT_ID, 'storage', SettingType::String);
    $settings->set(R2StorageSettings::ACCOUNT_ID, 'acct-1');
    $settings->define(R2StorageSettings::ACCESS_KEY_ID, 'storage', SettingType::String, isEncrypted: true);
    $settings->set(R2StorageSettings::ACCESS_KEY_ID, 'key-1');
    $settings->define(R2StorageSettings::SECRET_ACCESS_KEY, 'storage', SettingType::String, isEncrypted: true);
    $settings->set(R2StorageSettings::SECRET_ACCESS_KEY, 'secret-1');
    $settings->define(R2StorageSettings::BUCKET, 'storage', SettingType::String);
    $settings->set(R2StorageSettings::BUCKET, 'bucket-1');
    $settings->define(R2StorageSettings::ENDPOINT, 'storage', SettingType::String);
    $settings->set(R2StorageSettings::ENDPOINT, 'https://example.r2.cloudflarestorage.com');
}

function storageMigrationMockR2ToFakeDisk(): void
{
    Storage::fake('r2_test');

    test()->mock(R2Manager::class, fn ($mock) => $mock
        ->shouldReceive('disk')
        ->andReturnUsing(fn () => Storage::disk('r2_test')));
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('private');
});

it('reports counts across every known source without touching any disk', function () {
    $manager = testPlatformStaff(PlatformRole::ProductManager);
    $product = Product::create([
        'name' => 'Cotton Panjabi',
        'sku' => 'FW-2001',
        'category_id' => Category::create(['name' => 'Clothing'])->id,
    ]);
    app(ManageProductMedia::class)->add($manager, $product, UploadedFile::fake()->image('front.png'));

    app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    $report = app(RunStorageMigration::class)->handle('inventory');

    expect($report->sources['product_media'])->toBe(['total' => 1, 'already_on_r2' => 0, 'eligible' => 1])
        ->and($report->sources['stored_files'])->toBe(['total' => 1, 'already_on_r2' => 0, 'eligible' => 1])
        ->and($report->sources['kyc_documents'])->toBe(['total' => 0, 'already_on_r2' => 0, 'eligible' => 0]);
});

it('flags a row whose local file has gone missing, without writing anything', function () {
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    Storage::disk('private')->delete($stored->path);

    $report = app(RunStorageMigration::class)->handle('dry-run');

    expect($report->sources['stored_files']['missing_local_file'])->toBe(1)
        ->and($stored->fresh()->disk)->toBe('private');
});

it('refuses to migrate or verify before R2 is configured and switched on', function () {
    expect(fn () => app(RunStorageMigration::class)->handle('migrate'))
        ->toThrow(RuntimeException::class)
        ->and(fn () => app(RunStorageMigration::class)->handle('verify'))
        ->toThrow(RuntimeException::class);
});

it('migrates an eligible file to R2 and flips its disk column only after verifying the copy', function () {
    // Stored while R2 is off, so it lands on the local disk -- exactly the
    // "already stored before R2 was switched on" case this command exists
    // for.
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    storageMigrationEnableR2();
    storageMigrationMockR2ToFakeDisk();

    $report = app(RunStorageMigration::class)->handle('migrate');

    expect($report->sources['stored_files'])->toBe(['migrated' => 1])
        ->and($stored->fresh()->disk)->toBe('r2');

    Storage::disk('r2_test')->assertExists($stored->path);
    // The local original is never deleted on a migrate pass.
    Storage::disk('private')->assertExists($stored->path);
});

it('is safe to re-run: an already-migrated row is excluded from the next pass', function () {
    app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    storageMigrationEnableR2();
    storageMigrationMockR2ToFakeDisk();

    $first = app(RunStorageMigration::class)->handle('migrate');
    $second = app(RunStorageMigration::class)->handle('migrate');

    expect($first->sources['stored_files'])->toBe(['migrated' => 1])
        ->and($second->sources['stored_files'])->toBe([]);
});

it('does not flip the disk column when the remote copy fails to verify', function () {
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    storageMigrationEnableR2();

    $corrupting = Mockery::mock(Filesystem::class);
    $corrupting->shouldReceive('exists')->andReturn(false);
    $corrupting->shouldReceive('put')->andReturn(true);
    $corrupting->shouldReceive('get')->andReturn('not-the-same-bytes-at-all');

    $this->mock(R2Manager::class, fn ($mock) => $mock->shouldReceive('disk')->andReturn($corrupting));

    $report = app(RunStorageMigration::class)->handle('migrate');

    expect($report->sources['stored_files'])->toBe(['verification_failed' => 1])
        ->and($stored->fresh()->disk)->toBe('private');
});

it('verify re-confirms an already-migrated row against its own recorded checksum', function () {
    storageMigrationEnableR2();
    storageMigrationMockR2ToFakeDisk();

    app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    app(RunStorageMigration::class)->handle('migrate');
    $report = app(RunStorageMigration::class)->handle('verify');

    expect($report->sources['stored_files'])->toBe(['verified' => 1]);
});

it('verify flags a migrated row whose remote copy no longer matches', function () {
    storageMigrationEnableR2();
    storageMigrationMockR2ToFakeDisk();

    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    app(RunStorageMigration::class)->handle('migrate');

    // Tamper with the "remote" copy directly.
    Storage::disk('r2_test')->put($stored->path, 'tampered-bytes');

    $report = app(RunStorageMigration::class)->handle('verify');

    expect($report->sources['stored_files'])->toBe(['verification_failed' => 1]);
});
