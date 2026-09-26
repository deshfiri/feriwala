<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Exceptions\CmsMediaRefused;
use App\Domain\Cms\Models\Media;
use App\Domain\Cms\Models\PageRevision;
use App\Domain\Cms\Models\PageSection;
use App\Domain\Cms\Models\SeoSetting;
use App\Domain\Cms\Support\MediaReferenceWalker;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;

/**
 * Removes an uploaded asset's file and row together, so an admin can never
 * be left with a database row whose file is gone, or a file nothing in the
 * database still points at — but only once nothing still needs it (§34,
 * Stage 7 addendum). A deletion that broke a live page, a scheduled one, or
 * a past revision's ability to be restored would be a worse failure than
 * refusing the delete outright, so every reference is checked first: a
 * current draft section, any revision at all (published, scheduled or
 * superseded — restoring an old one must still work), and the global SEO
 * defaults' Open Graph image or organization logo.
 *
 * Row-locked and re-checked inside the transaction immediately before the
 * physical delete, which narrows but does not fully close the window
 * against a concurrent save introducing a brand new reference between the
 * first check and the delete — an accepted residual risk for an
 * infrequent, admin-only action, not a financial mutation. Calling this
 * twice on the same (or an already-deleted) media is safe: the second
 * call's route lookup finds nothing and answers 404 before this ever runs.
 */
class DeleteCmsMedia
{
    public function __construct(
        protected FilesystemFactory $filesystem,
        protected MediaReferenceWalker $walker,
    ) {}

    public function handle(Media $media): void
    {
        DB::transaction(function () use ($media) {
            $media = Media::query()->whereKey($media->id)->lockForUpdate()->firstOrFail();

            $reason = $this->referenceReason($media->public_id);

            if ($reason !== null) {
                throw CmsMediaRefused::stillReferenced($media->original_filename, $reason);
            }

            $this->filesystem->disk($media->disk)->delete($media->path);

            $media->delete();
        });
    }

    /**
     * A human-readable reason this media cannot be deleted, or null when it
     * is safe to remove.
     */
    protected function referenceReason(string $mediaPublicId): ?string
    {
        $referencesId = fn (array $content) => in_array(
            $mediaPublicId,
            $this->walker->collect($content),
            true,
        );

        foreach (PageSection::query()->cursor() as $section) {
            if ($referencesId($section->content->getArrayCopy())) {
                return 'a current draft section';
            }
        }

        foreach (PageRevision::query()->cursor() as $revision) {
            $content = $revision->content->getArrayCopy();

            if ($referencesId($content) || ($content['seo']['og_image_id'] ?? null) === $mediaPublicId) {
                return 'a page revision (including its history, which must remain restorable)';
            }
        }

        if (SeoSetting::query()
            ->where('default_og_image_id', $mediaPublicId)
            ->orWhere('organization_logo_id', $mediaPublicId)
            ->exists()) {
            return 'the global SEO defaults';
        }

        return null;
    }
}
