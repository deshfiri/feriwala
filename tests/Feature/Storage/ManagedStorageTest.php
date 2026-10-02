<?php

use App\Domain\Storage\Actions\DeleteManagedFile;
use App\Domain\Storage\Actions\StoreManagedFile;
use App\Domain\Storage\Enums\StorageVisibility;
use App\Domain\Storage\Exceptions\UnacceptableFile;
use App\Domain\Storage\ManagedStorage;
use App\Domain\Storage\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The shared managed-storage abstraction (beta-critical batch, Commit 4):
 * every managed file is validated from its own bytes, given a collision-safe
 * name, and recorded with mime type, size, a checksum and which disk it
 * actually landed on -- whichever disk that is. R2 is never touched here;
 * with no R2 settings configured, ManagedStorage resolves to the local
 * `public`/`private` disks, which Storage::fake() intercepts.
 */

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('private');
});

it('stores a public file with its metadata and a collision-safe path', function () {
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    $stored = app(StoreManagedFile::class)->handle(
        file: $file,
        purpose: 'test-assets',
        visibility: StorageVisibility::Public,
        allowedMimeTypes: ['image/png'],
        maxBytes: 2 * 1024 * 1024,
    );

    expect($stored->disk)->toBe('public')
        ->and($stored->visibility)->toBe(StorageVisibility::Public)
        ->and($stored->mime_type)->toBe('image/png')
        ->and($stored->size_bytes)->toBeGreaterThan(0)
        ->and($stored->checksum)->toHaveLength(64)
        ->and($stored->path)->toStartWith('test-assets/')
        ->and($stored->path)->not->toContain('logo');

    Storage::disk('public')->assertExists($stored->path);
});

it('stores a private file on the private disk', function () {
    $file = UploadedFile::fake()->image('doc.png');

    $stored = app(StoreManagedFile::class)->handle(
        file: $file,
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    expect($stored->disk)->toBe('private')
        ->and($stored->visibility)->toBe(StorageVisibility::Private);

    Storage::disk('private')->assertExists($stored->path);
});

it('refuses a file type not in the caller\'s allow-list, read from its own bytes', function () {
    $file = UploadedFile::fake()->create('shop.svg', 10, 'image/svg+xml');

    expect(fn () => app(StoreManagedFile::class)->handle(
        file: $file,
        purpose: 'test-assets',
        visibility: StorageVisibility::Public,
        allowedMimeTypes: ['image/png', 'image/jpeg'],
        maxBytes: 2 * 1024 * 1024,
    ))->toThrow(UnacceptableFile::class);

    expect(StoredFile::query()->count())->toBe(0);
});

it('refuses a file larger than the caller\'s own cap', function () {
    $file = UploadedFile::fake()->create('big.png', 3000, 'image/png');

    expect(fn () => app(StoreManagedFile::class)->handle(
        file: $file,
        purpose: 'test-assets',
        visibility: StorageVisibility::Public,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    ))->toThrow(UnacceptableFile::class);
});

it('deletes a tracked file\'s bytes and its row together', function () {
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('logo.png'),
        purpose: 'test-assets',
        visibility: StorageVisibility::Public,
        allowedMimeTypes: ['image/png'],
        maxBytes: 2 * 1024 * 1024,
    );

    app(DeleteManagedFile::class)->handle($stored);

    Storage::disk('public')->assertMissing($stored->path);
    expect(StoredFile::query()->find($stored->id))->toBeNull();
});

it('refuses to delete a file still within its retention window', function () {
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('logo.png'),
        purpose: 'test-assets',
        visibility: StorageVisibility::Public,
        allowedMimeTypes: ['image/png'],
        maxBytes: 2 * 1024 * 1024,
        retainUntil: now()->addDays(30),
    );

    expect(fn () => app(DeleteManagedFile::class)->handle($stored))->toThrow(RuntimeException::class);

    Storage::disk('public')->assertExists($stored->path);
});

it('cleans up an untracked path through the fallback visibility', function () {
    // A file stored before this abstraction existed: no row, so forPath()
    // must still remove it directly off the disk the fallback resolves to.
    Storage::disk('public')->put('legacy/old-logo.png', 'bytes');

    app(DeleteManagedFile::class)->forPath('legacy/old-logo.png', StorageVisibility::Public);

    Storage::disk('public')->assertMissing('legacy/old-logo.png');
});

it('is a no-op for a null or blank path, tracked or not', function () {
    app(DeleteManagedFile::class)->forPath(null, StorageVisibility::Public);
    app(DeleteManagedFile::class)->forPath('', StorageVisibility::Public);

    expect(true)->toBeTrue();
});

it('resolves to the local public disk when R2 is not configured, and gives back a working url', function () {
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('logo.png'),
        purpose: 'test-assets',
        visibility: StorageVisibility::Public,
        allowedMimeTypes: ['image/png'],
        maxBytes: 2 * 1024 * 1024,
    );

    expect(app(ManagedStorage::class)->url($stored))->toContain($stored->path);
});

it('gives no direct url for a private file', function () {
    $stored = app(StoreManagedFile::class)->handle(
        file: UploadedFile::fake()->image('doc.png'),
        purpose: 'test-private',
        visibility: StorageVisibility::Private,
        allowedMimeTypes: ['image/png'],
        maxBytes: 1024 * 1024,
    );

    expect(app(ManagedStorage::class)->url($stored))->toBeNull();
});
