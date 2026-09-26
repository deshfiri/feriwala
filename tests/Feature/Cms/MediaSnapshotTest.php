<?php

use App\Domain\Cms\Actions\DeleteCmsMedia;
use App\Domain\Cms\Actions\PublishPage;
use App\Domain\Cms\Actions\SaveSectionDraft;
use App\Domain\Cms\Enums\SectionKind;
use App\Domain\Cms\Exceptions\CmsMediaRefused;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\SeoSetting;
use App\Domain\Cms\Support\PublishedPageReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

/*
 * Media referenced by a section is frozen into the revision at publish
 * time (§34, Stage 7 addendum) — a published page must keep rendering the
 * same image at the same size with the same alt text even after the
 * underlying Media row changes, and a media asset still in use anywhere
 * must never be physically deletable.
 */

function mediaSnapshotTestMedia(): Media
{
    return Media::query()->create([
        'disk' => 'public',
        'path' => 'cms/original.jpg',
        'original_filename' => 'original.jpg',
        'mime_type' => 'image/jpeg',
        'size_bytes' => 1000,
        'width' => 1200,
        'height' => 800,
        'alt_text_en' => 'Original alt text',
        'alt_text_bn' => 'মূল অল্ট টেক্সট',
    ]);
}

beforeEach(function () {
    Storage::fake('public');
});

it('freezes media metadata into the revision at publish time', function () {
    $page = cmsTestPage();
    $media = mediaSnapshotTestMedia();

    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        ...cmsTestHeroContent(),
        'media_id' => $media->public_id,
    ]);

    app(PublishPage::class)->handle($page);

    $result = app(PublishedPageReader::class)->render('home', 'en');
    $hero = collect($result['sections'])->firstWhere('kind', 'hero');

    expect($hero['content']['media']['url'])->toBe($media->url())
        ->and($hero['content']['media']['width'])->toBe(1200)
        ->and($hero['content']['media']['alt'])->toBe('Original alt text');
});

it('keeps serving the frozen metadata after the media row is edited', function () {
    $page = cmsTestPage();
    $media = mediaSnapshotTestMedia();

    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        ...cmsTestHeroContent(),
        'media_id' => $media->public_id,
    ]);
    app(PublishPage::class)->handle($page);

    $media->update(['alt_text_en' => 'Changed after publish', 'width' => 99]);

    $result = app(PublishedPageReader::class)->render('home', 'en');
    $hero = collect($result['sections'])->firstWhere('kind', 'hero');

    expect($hero['content']['media']['alt'])->toBe('Original alt text')
        ->and($hero['content']['media']['width'])->toBe(1200);
});

it('replaces the frozen alt text with the section media_alt_override when present', function () {
    $page = cmsTestPage();
    $media = mediaSnapshotTestMedia();

    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        ...cmsTestHeroContent(),
        'media_id' => $media->public_id,
        'media_alt_override' => ['en' => 'Context-specific alt text', 'bn' => null],
    ]);

    app(PublishPage::class)->handle($page);

    $result = app(PublishedPageReader::class)->render('home', 'en');
    $hero = collect($result['sections'])->firstWhere('kind', 'hero');

    expect($hero['content']['media']['alt'])->toBe('Context-specific alt text');
});

it('refuses to delete media still referenced by a current draft section', function () {
    $page = cmsTestPage();
    $media = mediaSnapshotTestMedia();
    Storage::disk('public')->put($media->path, 'fake-bytes');

    app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        ...cmsTestHeroContent(),
        'media_id' => $media->public_id,
    ]);

    expect(fn () => app(DeleteCmsMedia::class)->handle($media))
        ->toThrow(CmsMediaRefused::class);

    Storage::disk('public')->assertExists($media->path);
});

it('refuses to delete media still referenced by a published revision, even after the draft section is removed', function () {
    $page = cmsTestPage();
    $media = mediaSnapshotTestMedia();

    $section = app(SaveSectionDraft::class)->handle($page, 'hero', SectionKind::Hero, [
        ...cmsTestHeroContent(),
        'media_id' => $media->public_id,
    ]);
    app(PublishPage::class)->handle($page);

    // The live draft no longer points at it, but the published revision
    // still does, and that revision must remain renderable.
    $section->update(['content' => cmsTestHeroContent()]);

    expect(fn () => app(DeleteCmsMedia::class)->handle($media))
        ->toThrow(CmsMediaRefused::class);
});

it('refuses to delete the global SEO Open Graph image', function () {
    $media = mediaSnapshotTestMedia();

    SeoSetting::query()->create([
        'locale' => 'en',
        'default_title' => 'Feriwala',
        'organization_name' => 'Feriwala',
        'default_og_image_id' => $media->public_id,
    ]);

    expect(fn () => app(DeleteCmsMedia::class)->handle($media))
        ->toThrow(CmsMediaRefused::class);
});

it('allows deleting media nothing references', function () {
    $media = mediaSnapshotTestMedia();
    Storage::disk('public')->put($media->path, 'fake-bytes');

    app(DeleteCmsMedia::class)->handle($media);

    expect(Media::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing($media->path);
});

it('is idempotent when the same deletion is attempted twice', function () {
    $media = mediaSnapshotTestMedia();
    Storage::disk('public')->put($media->path, 'fake-bytes');

    app(DeleteCmsMedia::class)->handle($media);

    expect(fn () => app(DeleteCmsMedia::class)->handle($media->fresh() ?? $media))
        ->toThrow(ModelNotFoundException::class);
});
