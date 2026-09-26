<?php

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Actions\DeleteCmsMedia;

/**
 * The one place that knows how a media reference is spelled inside a
 * section's `content` JSON (Stage 7 addendum): any key named `media_id`, or
 * ending in `_media_id` (`mobile_media_id`, `poster_media_id`, ...),
 * anywhere in the structure — including inside a repeated `items`/`steps`
 * array. Kind-agnostic on purpose: a new media-bearing field only has to
 * follow the naming convention, not be registered here, in the validator,
 * and in the snapshot resolver all separately.
 *
 * @see MediaSnapshotResolver freezes each reference into immutable metadata
 *      at publish time.
 * @see DeleteCmsMedia uses the same walk to refuse
 *      deleting anything still referenced.
 */
class MediaReferenceWalker
{
    /**
     * Every media public_id referenced anywhere in `$content`.
     *
     * @param  array<string, mixed>  $content
     * @return array<int, string>
     */
    public function collect(array $content): array
    {
        $ids = [];
        $this->walk($content, $ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<array-key, mixed>  $content  a list-shaped sub-array
     *                                            (`items`, `steps`) recurses through here with integer keys,
     *                                            so this is never narrowed to `array<string, mixed>`.
     * @param  array<int, string>  $ids
     */
    protected function walk(array $content, array &$ids): void
    {
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $this->walk($value, $ids);

                continue;
            }

            if (! is_string($key) || ! is_string($value) || $value === '') {
                continue;
            }

            if ($key === 'media_id' || str_ends_with($key, '_media_id')) {
                $ids[] = $value;
            }
        }
    }
}
