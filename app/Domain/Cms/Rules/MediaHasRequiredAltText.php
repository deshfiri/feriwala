<?php

namespace App\Domain\Cms\Rules;

use App\Domain\Cms\Models\Media;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * A section may only reference a media asset that already carries alt text
 * in both locales (§34's content-safety requirement, Stage 7 addendum) —
 * checked again here, at the point of *use*, not only at upload time,
 * because an asset can be uploaded once and referenced by several sections
 * over time, any of which could be the first to actually need it captioned.
 *
 * Runs after {@see Rule::exists()} in the field's
 * rule list, so a nonexistent id fails there first with the clearer
 * message; this only ever sees an id already known to exist.
 */
class MediaHasRequiredAltText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $media = Media::query()->wherePublicId($value)->first();

        if ($media !== null && ! $media->hasRequiredAltText()) {
            $fail('The selected image needs alt text in both English and Bangla before it can be used on the page.');
        }
    }
}
