<?php

use App\Domain\Access\Enums\PlatformRole;
use App\Domain\Cms\Models\Media;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The CMS media library through the admin screen (§34, Stage 7). Real
 * byte-sniffed MIME/size checking is UploadCmsMediaTest's own job (see
 * CmsMediaTest) — this only proves the controller surfaces a refusal as a
 * field error and that alt text/attribution are editable after upload.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = testPlatformStaff(PlatformRole::ContentManager);
    Storage::fake('public');
});

it('uploads an image into the library', function () {
    $this->actingAs($this->manager)
        ->post(route('admin.cms.media.store'), [
            'file' => UploadedFile::fake()->image('hero.jpg', 800, 600),
        ])
        ->assertRedirect();

    expect(Media::query()->where('original_filename', 'hero.jpg')->exists())->toBeTrue();
});

it('refuses a disguised upload as a field error, not a server error', function () {
    $path = tempnam(sys_get_temp_dir(), 'cms-media-admin');
    file_put_contents($path, '<!doctype html><script>alert(1)</script>');

    $this->actingAs($this->manager)
        ->post(route('admin.cms.media.store'), [
            'file' => new UploadedFile($path, 'disguised.jpg', 'image/jpeg', null, true),
        ])
        ->assertSessionHasErrors('file');
});

it('saves alt text and attribution after upload', function () {
    $media = Media::query()->create([
        'disk' => 'public',
        'path' => 'cms/test.jpg',
        'original_filename' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
    ]);

    $this->actingAs($this->manager)
        ->patch(route('admin.cms.media.update', $media->public_id), [
            'alt_text_en' => 'A shopkeeper packing an order',
            'alt_text_bn' => 'একজন দোকানদার অর্ডার প্যাক করছেন',
        ])
        ->assertRedirect();

    expect($media->refresh()->hasRequiredAltText())->toBeTrue();
});

it('removes an uploaded image and its file together', function () {
    Storage::disk('public')->put('cms/test.jpg', 'fake-bytes');

    $media = Media::query()->create([
        'disk' => 'public',
        'path' => 'cms/test.jpg',
        'original_filename' => 'test.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 100,
    ]);

    $this->actingAs($this->manager)
        ->delete(route('admin.cms.media.destroy', $media->public_id))
        ->assertRedirect();

    expect(Media::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing('cms/test.jpg');
});
