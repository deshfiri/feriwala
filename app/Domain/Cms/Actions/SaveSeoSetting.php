<?php

namespace App\Domain\Cms\Actions;

use App\Domain\Cms\Models\SeoSetting;
use App\Domain\Cms\Support\PublishedPageReader;

/**
 * Saves the global, per-locale SEO defaults every page's own override falls
 * back to (§34, see {@see PublishedPageReader::seo()}).
 * One row per locale, upserted rather than created afresh each time — the
 * seeder and this action are the only two places that ever write this table.
 */
class SaveSeoSetting
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(string $locale, array $attributes): SeoSetting
    {
        return SeoSetting::query()->updateOrCreate(['locale' => $locale], $attributes);
    }
}
