<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Models\Media;

/**
 * Sets an uploaded asset's alt text and attribution (§34's media-safety
 * requirements) — never its file, dimensions or path, which are fixed at
 * upload time by {@see UploadCmsMedia}. Alt text cannot be known until after
 * the file exists, so it is always a separate step from the upload itself.
 */
class UpdateCmsMedia
{
    public function handle(Media $media, ?string $altTextEn, ?string $altTextBn, ?string $attribution): Media
    {
        $media->update([
            'alt_text_en' => $altTextEn,
            'alt_text_bn' => $altTextBn,
            'attribution' => $attribution,
        ]);

        return $media;
    }
}
