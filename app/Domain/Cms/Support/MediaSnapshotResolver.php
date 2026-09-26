<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Freezes every media reference in a section's content into immutable
 * metadata at publish time (§34, Stage 7 addendum) — a published revision
 * must keep rendering the same image at the same size with the same alt
 * text even if the underlying {@see Media} row is later edited or its file
 * replaced. `PublishedPageReader` never queries the media table for a
 * published page; everything it needs is already in the revision's own
 * JSON.
 *
 * The original `..._media_id` field is left untouched (so
 * {@see MediaReferenceWalker} can still find it for
 * deletion protection); the resolved metadata is added alongside it under
 * the same name with `_id` dropped — `poster_media_id` gets a sibling
 * `poster_media`, `media_id` gets `media`.
 *
 * A sibling `{name}_alt_override` localized field (only `media_alt_override`
 * exists in a schema today, on Hero) replaces the frozen `alt` when present —
 * this is how a section can say "the image's own library alt text isn't
 * right in this context" without editing the shared Media row.
 */
class MediaSnapshotResolver
{
    public function __construct(protected MediaReferenceWalker $walker) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<array-key, mixed>
     */
    public function resolve(array $content): array
    {
        $ids = $this->walker->collect($content);

        if ($ids === []) {
            return $content;
        }

        $media = Media::query()->whereIn('public_id', $ids)->get()->keyBy('public_id');

        return $this->substitute($content, $media);
    }

    /**
     * @param  array<array-key, mixed>  $content  a list-shaped sub-array
     *                                            (`items`, `steps`) recurses through here with integer keys,
     *                                            so this is never narrowed to `array<string, mixed>`.
     * @param  Collection<string, Media>  $media
     * @return array<array-key, mixed>
     */
    protected function substitute(array $content, Collection $media): array
    {
        $result = [];

        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->substitute($value, $media);

                continue;
            }

            $result[$key] = $value;

            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }

            if ($key === 'media_id' || str_ends_with($key, '_media_id')) {
                $resolvedKey = $key === 'media_id' ? 'media' : Str::beforeLast($key, '_id');
                $item = $media->get($value);
                $snapshot = $item === null ? null : $this->snapshot($item);

                $override = $content["{$resolvedKey}_alt_override"] ?? null;

                if ($snapshot !== null && is_array($override) && (filled($override['en'] ?? null) || filled($override['bn'] ?? null))) {
                    $snapshot['alt'] = $override;
                }

                $result[$resolvedKey] = $snapshot;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Media $media): array
    {
        return [
            'public_id' => $media->public_id,
            'url' => $media->url(),
            'width' => $media->width,
            'height' => $media->height,
            'mime_type' => $media->mime_type,
            'alt' => [
                'en' => $media->alt_text_en,
                'bn' => $media->alt_text_bn,
            ],
        ];
    }
}
